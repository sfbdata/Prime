<?php

declare(strict_types=1);

namespace App\Inteligencia\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\SolicitarAnaliseDaPastaInput;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\ContextoVazioException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\MuitasSolicitacoesException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\VisibilidadeDaAnaliseDaPasta;
use App\Inteligencia\UseCase\ContarOQueAIaSabeDaPastaUseCase;
use App\Inteligencia\UseCase\ListarAnalisesDosAgentesUseCase;
use App\Inteligencia\UseCase\ListarAnalisesDosComandosUseCase;
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
 * Os agentes, comandos e relatórios da BlueJus IA na pasta (drawer do cabeçalho). Guarda IDÊNTICA
 * à do `AnalisePushController`: a pasta é do escritório da sessão (senão 404), o usuário pode VÊ-LA
 * (403) e tem `modules.inteligencia.view` (403). Agente ou comando desconhecido na URL é 404.
 *
 * Contrato para a tela:
 *   GET  /pasta/{id}/ia/agentes                      → fragmento `inteligencia/_agentes_painel.html.twig`
 *                                                      (abas Análise e Relatórios: grade "O que a IA sabe",
 *                                                      comandos e relatórios com as análises de cada um)
 *   POST /pasta/{id}/ia/comandos/{comando}/analises  → 202 {id, status, …} | 200 se devolveu a última ("nada novo")
 *                                                      409 {motivo, mensagem} indisponível/bloqueado/sem dados · 429 cota/ritmo · 503 fila
 *   GET  /pasta/{id}/ia/comandos/{comando}/analises  → fragmento `inteligencia/_analises_comando.html.twig`
 *   POST /pasta/{id}/ia/agentes/{agente}/analises    → idem, por agente (rotas anteriores aos comandos, mantidas)
 *   GET  /pasta/{id}/ia/agentes/{agente}/analises    → fragmento `inteligencia/_analises_agente.html.twig`
 * Status, lida, excluir e interna são as rotas por id da fatia 1 (`/pasta/{id}/ia/analises/{analiseId}…`).
 * CSRF: `_token` (campo ou header X-CSRF-Token); id `inteligencia_agentes_{pastaId}` (um para o drawer).
 */
#[Route('/pasta/{id}/ia', requirements: ['id' => '\d+'])]
final class AnaliseDaPastaController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly SolicitarAnaliseDaPastaUseCase $solicitar,
        private readonly ListarAnalisesDosAgentesUseCase $listar,
        private readonly ListarAnalisesDosComandosUseCase $listarComandos,
        private readonly ContarOQueAIaSabeDaPastaUseCase $contarOQueSabe,
        // Interface (não a classe) pelo mesmo motivo do AnalisePushController: dublê em teste.
        private readonly RateLimiterFactoryInterface $inteligenciaSolicitarLimiter,
        private readonly VisibilidadeDaAnaliseDaPasta $visibilidadeDaAnalise,
    ) {
    }

    #[Route('/agentes', name: 'inteligencia_agentes_painel', methods: ['GET'])]
    public function painel(Pasta $pasta): Response
    {
        [$user, $tenant] = $this->guardar($pasta);
        $ocultas = $this->visibilidadeDaAnalise->abasOcultasPara($pasta, $user);

        return $this->render('inteligencia/_agentes_painel.html.twig', [
            'painel' => $this->listarComandos->executar($pasta, $tenant, $ocultas),
            'sabe' => $this->contarOQueSabe->executar($pasta, $tenant, $ocultas),
            'pastaId' => (int) $pasta->getId(),
        ]);
    }

    #[Route('/agentes/{agente}/analises', name: 'inteligencia_agente_solicitar', requirements: ['agente' => '[a-z]+'], methods: ['POST'])]
    public function solicitar(Pasta $pasta, string $agente, Request $request): JsonResponse
    {
        [$user, $tenant] = $this->guardar($pasta);

        return $this->pedir($pasta, new SolicitarAnaliseDaPastaInput((int) $pasta->getId(), $this->agenteDaRota($agente)), $user, $tenant, $request);
    }

    #[Route('/comandos/{comando}/analises', name: 'inteligencia_comando_solicitar', requirements: ['comando' => '[a-z_]+'], methods: ['POST'])]
    public function solicitarComando(Pasta $pasta, string $comando, Request $request): JsonResponse
    {
        [$user, $tenant] = $this->guardar($pasta);

        return $this->pedir($pasta, SolicitarAnaliseDaPastaInput::doComando((int) $pasta->getId(), $this->comandoDaRota($comando)), $user, $tenant, $request);
    }

    #[Route('/comandos/{comando}/analises', name: 'inteligencia_comando_listar', requirements: ['comando' => '[a-z_]+'], methods: ['GET'])]
    public function listarComando(Pasta $pasta, string $comando): Response
    {
        [$user, $tenant] = $this->guardar($pasta);
        $comandoEscolhido = $this->comandoDaRota($comando);

        return $this->render('inteligencia/_analises_comando.html.twig', [
            'saida' => $this->listarComandos->executarParaComando($pasta, $tenant, $comandoEscolhido, $this->visibilidadeDaAnalise->abasOcultasPara($pasta, $user)),
            'pastaId' => (int) $pasta->getId(),
        ]);
    }

    /** O pedido em si, igual para agente e comando: CSRF, ritmo, UseCase e a tradução das recusas. */
    private function pedir(Pasta $pasta, SolicitarAnaliseDaPastaInput $input, User $user, Tenant $tenant, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('inteligencia_agentes_' . $pasta->getId(), $this->tokenDe($request))) {
            return $this->recusa('csrf', 'Sessão expirada. Recarregue a página e tente de novo.', Response::HTTP_FORBIDDEN);
        }

        // Mesmo limitador do Push, por usuário: a cota de ritmo é da pessoa, não do botão. Só gasta quando uma
        // análise nova vai ser criada: clicar num comando para ver o resultado ("nada novo") não gasta.
        $limitador = $this->inteligenciaSolicitarLimiter->create((string) $user->getId());
        $antesDeCriar = static function () use ($limitador): void {
            if (!$limitador->consume()->isAccepted()) {
                throw new MuitasSolicitacoesException();
            }
        };

        try {
            $saida = $this->solicitar->executar($input, $user, $tenant, $antesDeCriar);
        } catch (MuitasSolicitacoesException) {
            return $this->recusa('muitas_solicitacoes', 'Muitos pedidos em sequência. Aguarde um instante.', Response::HTTP_TOO_MANY_REQUESTS);
        } catch (PastaNaoEncontradaException $e) {
            throw $this->createNotFoundException($e->getMessage());
        } catch (InteligenciaIndisponivelException $e) {
            $status = $e->motivo->ehLimiteDeCota() ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_CONFLICT;

            return $this->recusa($e->motivo->codigoDaRecusa(), $e->motivo->mensagem(), $status);
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

    #[Route('/agentes/{agente}/analises', name: 'inteligencia_agente_listar', requirements: ['agente' => '[a-z]+'], methods: ['GET'])]
    public function listar(Pasta $pasta, string $agente): Response
    {
        [$user, $tenant] = $this->guardar($pasta);
        $agenteEscolhido = $this->agenteDaRota($agente);

        return $this->render('inteligencia/_analises_agente.html.twig', [
            'saida' => $this->listar->executarParaAgente($pasta, $tenant, $agenteEscolhido, $this->visibilidadeDaAnalise->abasOcultasPara($pasta, $user)),
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

    private function comandoDaRota(string $comando): Comando
    {
        return Comando::deRota($comando) ?? throw $this->createNotFoundException('Comando não encontrado.');
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
