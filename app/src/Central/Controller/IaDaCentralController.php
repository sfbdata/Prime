<?php

declare(strict_types=1);

namespace App\Central\Controller;

use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\UseCase\ConsultarStatusDaPerguntaDaIaUseCase;
use App\Central\UseCase\PerguntarABlueJusIaUseCase;
use App\Entity\Auth\User;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\MuitasSolicitacoesException;
use App\Inteligencia\Exception\PerguntaInvalidaException;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pergunta ao BlueJus IA na conversa da Central (F3, PR 3a, `docs/specs/ia-chat-central.md`). Mesma guarda do resto da
 * Central (vínculo ativo, sem permissão de módulo da IA por ora) e as mesmas regras de ritmo da pasta
 * (`inteligencia_solicitar`, por usuário).
 *
 * As respostas levam só ids e estado: NUNCA o texto da pergunta nem da resposta (a tela lê a conversa pelo histórico
 * e pelo polling de novidades). Escrita exige o CSRF `ajax` no cabeçalho `X-CSRF-Token`.
 */
#[Route('/central/ia')]
final class IaDaCentralController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    /** Corpo bruto acima disso nem chega ao validador (o limite do texto é 2000 caracteres). */
    private const TAMANHO_BRUTO_MAXIMO = 16_000;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PerguntarABlueJusIaUseCase $perguntar,
        private readonly ConsultarStatusDaPerguntaDaIaUseCase $consultarStatus,
        // Interface (não a classe), como na pasta: dublê em teste.
        private readonly RateLimiterFactoryInterface $inteligenciaSolicitarLimiter,
    ) {
    }

    #[Route('/perguntas', name: 'central_ia_pergunta', methods: ['POST'])]
    public function perguntar(Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $usuario = $this->usuario();

        $conteudo = $this->corpo($request)['conteudo'] ?? null;
        if (!is_string($conteudo)) {
            return $this->recusa('pergunta_invalida', 'Escreva a pergunta.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (strlen($conteudo) > self::TAMANHO_BRUTO_MAXIMO) {
            return $this->recusa('pergunta_invalida', PerguntaInvalidaException::longaDemais(2000)->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saida = $this->perguntar->executar($usuario, $this->tenantContext->getCurrentTenant(), $conteudo, $this->limitadorDe($usuario));
        } catch (SemAcessoACentralException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (PerguntaInvalidaException $e) {
            return $this->recusa($e->motivo(), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ConversaNaoEncontradaException) {
            return new JsonResponse(['erro' => 'Conversa não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (ConversaCriadaAoMesmoTempoException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (MuitasSolicitacoesException|InteligenciaIndisponivelException|FilaIndisponivelException $e) {
            return $this->recusaDe($e);
        }

        return new JsonResponse(
            [
                'analiseId' => $saida->analiseId,
                'conversaId' => $saida->conversaId,
                'mensagemId' => $saida->mensagemId,
                'status' => $saida->status,
                'emAndamento' => $saida->emAndamento,
            ],
            $saida->emAndamento ? Response::HTTP_ACCEPTED : Response::HTTP_CREATED,
        );
    }

    #[Route('/perguntas/{analiseId}', name: 'central_ia_pergunta_status', requirements: ['analiseId' => '\d{1,9}'], methods: ['GET'])]
    public function status(int $analiseId): JsonResponse
    {
        try {
            $saida = $this->consultarStatus->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $analiseId);
        } catch (SemAcessoACentralException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (AnaliseNaoEncontradaException) {
            return new JsonResponse(['erro' => 'Pergunta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $resposta = new JsonResponse([
            'analiseId' => $saida->analiseId,
            'conversaId' => $saida->conversaId,
            'status' => $saida->status,
            'emAndamento' => $saida->emAndamento,
            'terminal' => $saida->terminal,
            'mensagem' => $saida->mensagem,
        ]);
        $resposta->headers->set('Cache-Control', 'private, no-store');

        return $resposta;
    }

    /**
     * O limitador por pessoa; o UseCase o chama só quando uma pergunta nova vai ser criada (a que já está em andamento
     * não gasta) e dentro da trava da conversa.
     */
    private function limitadorDe(User $usuario): callable
    {
        $factory = $this->inteligenciaSolicitarLimiter;
        $chave = (string) $usuario->getId();

        return static function () use ($factory, $chave): void {
            if (!$factory->create($chave)->consume()->isAccepted()) {
                throw new MuitasSolicitacoesException();
            }
        };
    }

    /** A tradução das recusas do UseCase, igual à da pasta. */
    private function recusaDe(\Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof MuitasSolicitacoesException => $this->recusa('muitas_solicitacoes', 'Muitos pedidos em sequência. Aguarde um instante.', Response::HTTP_TOO_MANY_REQUESTS),
            $e instanceof InteligenciaIndisponivelException => $this->recusa(
                $e->motivo->codigoDaRecusa(),
                $e->motivo->mensagem(),
                $e->motivo->ehLimiteDeCota() ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_CONFLICT,
            ),
            $e instanceof FilaIndisponivelException => $this->recusa($e->motivo(), $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE),
            default => throw $e,
        };
    }

    private function recusa(string $motivo, string $mensagem, int $status): JsonResponse
    {
        return new JsonResponse(['motivo' => $motivo, 'mensagem' => $mensagem], $status);
    }

    /** @return array<string, mixed> */
    private function corpo(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function usuario(): User
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $usuario;
    }
}
