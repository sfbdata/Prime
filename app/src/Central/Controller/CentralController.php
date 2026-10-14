<?php

declare(strict_types=1);

namespace App\Central\Controller;

use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\UseCase\AbrirConversaUseCase;
use App\Central\UseCase\EnviarMensagemUseCase;
use App\Central\UseCase\ListarContatosDaCentralUseCase;
use App\Central\UseCase\ListarConversasUseCase;
use App\Central\UseCase\ListarMensagensDaConversaUseCase;
use App\Central\UseCase\MarcarConversaComoLidaUseCase;
use App\Central\UseCase\ObterNovidadesDaCentralUseCase;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Central de Comunicação A1 — conversas 1:1 persistidas (`docs/specs/central-comunicacao-a1.md`).
 *
 * Toda a regra de acesso mora nos UseCases (vínculo ativo + participação); aqui só HTTP: ler o pedido,
 * chamar, traduzir a exceção de domínio no status. Escrita exige o token CSRF `ajax` no cabeçalho
 * `X-CSRF-Token` (o interceptador do layout injeta em todo fetch same-origin).
 */
#[Route('/central')]
final class CentralController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    /** Maior valor de uma coluna `int` do PostgreSQL — o tipo dos ids da Central. */
    private const MAIOR_ID = 2_147_483_647;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListarContatosDaCentralUseCase $listarContatos,
        private readonly ListarConversasUseCase $listarConversas,
        private readonly AbrirConversaUseCase $abrirConversa,
        private readonly ListarMensagensDaConversaUseCase $listarMensagens,
        private readonly EnviarMensagemUseCase $enviarMensagem,
        private readonly MarcarConversaComoLidaUseCase $marcarComoLida,
        private readonly ObterNovidadesDaCentralUseCase $obterNovidades,
    ) {
    }

    #[Route('', name: 'central_index', methods: ['GET'])]
    public function index(): Response
    {
        try {
            $this->listarContatos->executar($this->usuario(), $this->tenantContext->getCurrentTenant());
        } catch (SemAcessoACentralException) {
            throw $this->createAccessDeniedException('Sem acesso à Central neste escritório.');
        }

        return $this->render('central/index.html.twig', ['eu' => $this->usuario()]);
    }

    #[Route('/contatos', name: 'central_contatos', methods: ['GET'])]
    public function contatos(): JsonResponse
    {
        return $this->responder(fn () => ['contatos' => $this->listarContatos->executar($this->usuario(), $this->tenantContext->getCurrentTenant())]);
    }

    #[Route('/conversas', name: 'central_conversas', methods: ['GET'])]
    public function conversas(): JsonResponse
    {
        return $this->responder(fn () => ['conversas' => $this->listarConversas->executar($this->usuario(), $this->tenantContext->getCurrentTenant())]);
    }

    #[Route('/conversas', name: 'central_conversa_abrir', methods: ['POST'])]
    public function abrir(Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $destinatarioId = $this->inteiroDoCorpo($request, 'destinatarioId');

        return $this->responder(fn () => ['conversa' => $this->abrirConversa->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $destinatarioId)]);
    }

    #[Route('/conversas/{id}/mensagens', name: 'central_mensagens', requirements: ['id' => '\d{1,9}'], methods: ['GET'])]
    public function mensagens(int $id, Request $request): JsonResponse
    {
        $antesDe = self::idDaQuery($request, 'antesDe');

        return $this->responder(fn () => $this->listarMensagens->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, $antesDe));
    }

    #[Route('/conversas/{id}/mensagens', name: 'central_mensagem_enviar', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function enviar(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $conteudo = $this->corpo($request)['conteudo'] ?? null;
        if (!is_string($conteudo)) {
            return $this->json(['erro' => 'Mensagem inválida.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->responder(
            fn () => ['mensagem' => $this->enviarMensagem->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, $conteudo)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/conversas/{id}/lida', name: 'central_conversa_lida', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function lida(int $id, Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $ateId = $this->inteiroDoCorpo($request, 'ateId');

        return $this->responder(fn () => ['ultimaLidaId' => $this->marcarComoLida->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, $ateId)]);
    }

    #[Route('/novidades', name: 'central_novidades', methods: ['GET'])]
    public function novidades(Request $request): JsonResponse
    {
        // Sem `desde` (ou com valor inválido) = primeira chamada da tela, só o cursor; `desde=0` é cursor de verdade.
        // O inválido não vira 0: isso devolveria o histórico inteiro como novidade.
        $desde = self::idDaQuery($request, 'desde', aceitaZero: true);

        return $this->responder(fn () => $this->obterNovidades->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $desde));
    }

    /** @param callable(): mixed $acao */
    private function responder(callable $acao, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            return $this->json($acao(), $status);
        } catch (SemAcessoACentralException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (ConversaNaoEncontradaException) {
            return $this->json(['erro' => 'Conversa não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (PedidoInvalidoNaCentralException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ConversaCriadaAoMesmoTempoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
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

    /**
     * Inteiro positivo do corpo JSON, dentro do `int` do PostgreSQL; ausente, torto ou grande demais vira 0, que
     * nenhum id tem (o UseCase recusa) — em vez de chegar ao banco e virar 500.
     */
    private function inteiroDoCorpo(Request $request, string $chave): int
    {
        $valor = $this->corpo($request)[$chave] ?? null;

        return is_int($valor) && $valor > 0 && $valor <= self::MAIOR_ID ? $valor : 0;
    }

    private static function idDaQuery(Request $request, string $chave, bool $aceitaZero = false): ?int
    {
        $valor = $request->query->get($chave);
        if (!is_string($valor) || !ctype_digit($valor) || strlen($valor) > 10 || (int) $valor > self::MAIOR_ID) {
            return null;
        }

        return ((int) $valor > 0 || $aceitaZero) ? (int) $valor : null;
    }

    private function usuario(): User
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $usuario;
    }
}
