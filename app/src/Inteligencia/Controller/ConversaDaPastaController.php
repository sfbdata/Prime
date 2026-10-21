<?php

declare(strict_types=1);

namespace App\Inteligencia\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\PerguntarSobreAPastaInput;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\MuitasSolicitacoesException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Exception\PerguntaInvalidaException;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Inteligencia\UseCase\ListarConversaDaPastaUseCase;
use App\Inteligencia\UseCase\PerguntarSobreAPastaUseCase;
use App\Pasta\Entity\Pasta;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A aba "Perguntar" do drawer da BlueJus IA (spec inteligencia-drawer-da-pasta, F3). Mesmas guardas
 * das rotas de comando do `AnaliseDaPastaController`: a pasta é do escritório da sessão (senão
 * 404), o usuário pode VÊ-LA (403) e tem `modules.inteligencia.view` (403).
 *
 * Contrato para a tela:
 *   POST /pasta/{id}/ia/conversa/perguntas  → 202 {id, status, …, pergunta} (nova, ou a que já estava em
 *                                             andamento, com `aviso`) · 200 se a fila falhou e a análise já é falha
 *                                             422 {motivo: pergunta_invalida} texto vazio ou longo
 *                                             409 {motivo, mensagem} indisponível/bloqueado · 429 cota/ritmo · 503 fila
 *   GET  /pasta/{id}/ia/conversa            → fragmento `inteligencia/_conversa.html.twig` (só a conversa da
 *                                             pessoa logada; `data-ia-em-andamento` para o polling)
 * CSRF: `_token` (campo ou header X-CSRF-Token); id `inteligencia_agentes_{pastaId}` (o do drawer).
 * O texto vai no campo `texto`. Nunca é logado.
 */
#[Route('/pasta/{id}/ia/conversa', requirements: ['id' => '\d+'])]
final class ConversaDaPastaController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PerguntarSobreAPastaUseCase $perguntar,
        private readonly ListarConversaDaPastaUseCase $listar,
        // Interface (não a classe), como no AnaliseDaPastaController: dublê em teste.
        private readonly RateLimiterFactoryInterface $inteligenciaSolicitarLimiter,
        private readonly VisibilidadeDaAnaliseDaPasta $visibilidadeDaAnalise,
    ) {
    }

    #[Route('', name: 'inteligencia_conversa_listar', methods: ['GET'])]
    public function listar(Pasta $pasta): Response
    {
        [$user, $tenant] = $this->guardar($pasta);

        return $this->render('inteligencia/_conversa.html.twig', [
            'conversa' => $this->listar->executar($pasta, $user, $tenant, $this->visibilidadeDaAnalise->abasOcultasPara($pasta, $user)),
            'pastaId' => (int) $pasta->getId(),
        ]);
    }

    #[Route('/perguntas', name: 'inteligencia_conversa_perguntar', methods: ['POST'])]
    public function perguntar(Pasta $pasta, Request $request): JsonResponse
    {
        [$user, $tenant] = $this->guardar($pasta);
        if (!$this->isCsrfTokenValid('inteligencia_agentes_' . $pasta->getId(), $this->tokenDe($request))) {
            return $this->recusa('csrf', 'Sessão expirada. Recarregue a página e tente de novo.', Response::HTTP_FORBIDDEN);
        }

        try {
            $input = new PerguntarSobreAPastaInput((int) $pasta->getId(), (string) $request->request->get('texto', ''));
            $saida = $this->perguntar->executar($input, $user, $tenant, $this->limitadorDe($user));
        } catch (PerguntaInvalidaException $e) {
            return $this->recusa($e->motivo(), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PastaNaoEncontradaException $e) {
            throw $this->createNotFoundException($e->getMessage());
        } catch (MuitasSolicitacoesException|InteligenciaIndisponivelException|ContextoBloqueadoException|FilaIndisponivelException $e) {
            return $this->recusaDe($e);
        }

        return new JsonResponse($saida->paraJson(), $saida->emAndamento() ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }

    /**
     * O limitador do Push e dos comandos, por pessoa; o UseCase o chama só quando uma pergunta nova
     * vai ser criada (a que já está em andamento não gasta).
     */
    private function limitadorDe(User $user): callable
    {
        $limitador = $this->inteligenciaSolicitarLimiter->create((string) $user->getId());

        return static function () use ($limitador): void {
            if (!$limitador->consume()->isAccepted()) {
                throw new MuitasSolicitacoesException();
            }
        };
    }

    /** A tradução das recusas do UseCase, igual à do pedido de comando. */
    private function recusaDe(\Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof MuitasSolicitacoesException => $this->recusa('muitas_solicitacoes', 'Muitos pedidos em sequência. Aguarde um instante.', Response::HTTP_TOO_MANY_REQUESTS),
            $e instanceof InteligenciaIndisponivelException => $this->recusa(
                $e->motivo->codigoDaRecusa(),
                $e->motivo->mensagem(),
                $e->motivo->ehLimiteDeCota() ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_CONFLICT,
            ),
            $e instanceof ContextoBloqueadoException => $this->recusa($e->motivo(), $e->getMessage(), Response::HTTP_CONFLICT),
            $e instanceof FilaIndisponivelException => $this->recusa($e->motivo(), $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE),
            default => throw $e,
        };
    }

    /** @return array{User, Tenant} */
    private function guardar(Pasta $pasta): array
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        // O resolver busca por PK e o TenantFilter não se aplica a find(): a conferência é explícita.
        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($user, $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            throw $this->createAccessDeniedException('Sem permissão para ver esta pasta.');
        }

        if (!$this->permissionChecker->canAccessModule($user, $tenant, DisponibilidadeDeInteligencia::MODULO)) {
            throw $this->createAccessDeniedException(Disponibilidade::SemPermissao->mensagem());
        }

        return [$user, $tenant];
    }

    private function tokenDe(Request $request): string
    {
        $token = $request->request->get('_token');
        if (is_string($token) && $token !== '') {
            return $token;
        }

        return (string) $request->headers->get('X-CSRF-Token', '');
    }

    private function recusa(string $motivo, string $mensagem, int $status): JsonResponse
    {
        return new JsonResponse(['motivo' => $motivo, 'mensagem' => $mensagem], $status);
    }
}
