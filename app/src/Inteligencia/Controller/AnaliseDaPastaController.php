<?php

declare(strict_types=1);

namespace App\Inteligencia\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\SolicitarAnaliseDaPastaInput;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\ContextoVazioException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\UseCase\ListarAnalisesDosAgentesUseCase;
use App\Inteligencia\UseCase\SolicitarAnaliseDaPastaUseCase;
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
 * Os agentes da BlueJus IA na pasta (drawer do cabeçalho). Guarda IDÊNTICA à do
 * `AnalisePushController`: a pasta é do escritório da sessão (senão 404), o usuário pode VÊ-LA
 * (403) e tem `modules.inteligencia.view` (403). Agente desconhecido na URL é 404.
 *
 * Contrato para a tela:
 *   GET  /pasta/{id}/ia/agentes                      → fragmento `inteligencia/_agentes_painel.html.twig`
 *   POST /pasta/{id}/ia/agentes/{agente}/analises    → 202 {id, status, …} | 200 se devolveu a última ("nada novo")
 *                                                      409 {motivo, mensagem} indisponível/bloqueado/sem dados · 429 cota/ritmo · 503 fila
 *   GET  /pasta/{id}/ia/agentes/{agente}/analises    → fragmento `inteligencia/_analises_agente.html.twig`
 * Status, lida, excluir e interna são as rotas por id da fatia 1 (`/pasta/{id}/ia/analises/{analiseId}…`).
 * CSRF: `_token` (campo ou header X-CSRF-Token); id `inteligencia_agentes_{pastaId}` (um para o drawer).
 */
#[Route('/pasta/{id}/ia/agentes', requirements: ['id' => '\d+'])]
final class AnaliseDaPastaController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly SolicitarAnaliseDaPastaUseCase $solicitar,
        private readonly ListarAnalisesDosAgentesUseCase $listar,
        // Interface (não a classe) pelo mesmo motivo do AnalisePushController: dublê em teste.
        private readonly RateLimiterFactoryInterface $inteligenciaSolicitarLimiter,
    ) {
    }

    #[Route('', name: 'inteligencia_agentes_painel', methods: ['GET'])]
    public function painel(Pasta $pasta): Response
    {
        [, $tenant] = $this->guardar($pasta);

        return $this->render('inteligencia/_agentes_painel.html.twig', [
            'painel' => $this->listar->executar($pasta, $tenant),
            'pastaId' => (int) $pasta->getId(),
        ]);
    }

    #[Route('/{agente}/analises', name: 'inteligencia_agente_solicitar', requirements: ['agente' => '[a-z]+'], methods: ['POST'])]
    public function solicitar(Pasta $pasta, string $agente, Request $request): JsonResponse
    {
        [$user, $tenant] = $this->guardar($pasta);
        $agenteEscolhido = $this->agenteDaRota($agente);

        if (!$this->isCsrfTokenValid('inteligencia_agentes_' . $pasta->getId(), $this->tokenDe($request))) {
            return $this->recusa('csrf', 'Sessão expirada. Recarregue a página e tente de novo.', Response::HTTP_FORBIDDEN);
        }

        // Mesmo limitador do Push, por usuário: a cota de ritmo é da pessoa, não do botão.
        if (!$this->inteligenciaSolicitarLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->recusa('muitas_solicitacoes', 'Muitos pedidos em sequência. Aguarde um instante.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $saida = $this->solicitar->executar(new SolicitarAnaliseDaPastaInput((int) $pasta->getId(), $agenteEscolhido), $user, $tenant);
        } catch (PastaNaoEncontradaException $e) {
            throw $this->createNotFoundException($e->getMessage());
        } catch (InteligenciaIndisponivelException $e) {
            $status = $e->motivo === Disponibilidade::LimiteAtingido ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_CONFLICT;

            return $this->recusa($e->motivo->value, $e->motivo->mensagem(), $status);
        } catch (ContextoBloqueadoException $e) {
            return $this->recusa($e->motivo(), $e->getMessage(), Response::HTTP_CONFLICT);
        } catch (ContextoVazioException $e) {
            return $this->recusa($e->motivo(), $e->getMessage(), Response::HTTP_CONFLICT);
        } catch (FilaIndisponivelException $e) {
            return $this->recusa($e->motivo(), $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $codigo = $saida->emAndamento ? Response::HTTP_ACCEPTED : Response::HTTP_OK;

        return new JsonResponse($saida->paraJson(), $codigo);
    }

    #[Route('/{agente}/analises', name: 'inteligencia_agente_listar', requirements: ['agente' => '[a-z]+'], methods: ['GET'])]
    public function listar(Pasta $pasta, string $agente): Response
    {
        [, $tenant] = $this->guardar($pasta);
        $agenteEscolhido = $this->agenteDaRota($agente);

        return $this->render('inteligencia/_analises_agente.html.twig', [
            'saida' => $this->listar->executarParaAgente($pasta, $tenant, $agenteEscolhido),
            'pastaId' => (int) $pasta->getId(),
        ]);
    }

    // -----------------------------------------------------------------------------------------

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

    private function agenteDaRota(string $agente): Agente
    {
        return Agente::deRota($agente) ?? throw $this->createNotFoundException('Agente não encontrado.');
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
