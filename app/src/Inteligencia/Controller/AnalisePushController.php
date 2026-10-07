<?php

declare(strict_types=1);

namespace App\Inteligencia\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\SolicitarResumoDoPushInput;
use App\Inteligencia\Enum\Disponibilidade;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\ContextoVazioException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\UseCase\AlternarAnaliseInternaUseCase;
use App\Inteligencia\UseCase\ConsultarStatusDaAnaliseUseCase;
use App\Inteligencia\UseCase\ExcluirAnaliseUseCase;
use App\Inteligencia\UseCase\ExportarAnaliseEmTextoUseCase;
use App\Inteligencia\UseCase\ListarAnalisesDaPastaUseCase;
use App\Inteligencia\UseCase\ListarAuditoriaDaPastaUseCase;
use App\Inteligencia\UseCase\MarcarAnaliseComoLidaUseCase;
use App\Inteligencia\UseCase\SolicitarResumoDoPushUseCase;
use App\Pasta\Entity\Pasta;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A BlueJus IA dentro da pasta (aba Push). Guarda idêntica ao `PastaPushProcessualController` —
 * a pasta é do escritório da sessão (senão 404) e o usuário pode VÊ-LA — mais a capacidade
 * `modules.inteligencia.view`: a IA gasta cota e envia dado a terceiro, então quem pode ver a pasta
 * não necessariamente pode acionar o provedor (spec §3.5).
 *
 * Contrato para a tela (1B):
 *   POST /pasta/{id}/ia/push/analises           → 202 {id, status, …} | 200 se devolveu a última ("nada novo")
 *                                                 409 {motivo, mensagem} indisponível/bloqueado/vazio · 429 cota/ritmo · 503 fila indisponível
 *   GET  /pasta/{id}/ia/push/analises           → fragmento `inteligencia/_analises_push.html.twig`
 *   GET  /pasta/{id}/ia/analises/{analiseId}    → {id, status, statusRotulo, emAndamento, terminal, mensagem, motivoTecnico}
 *   GET  /pasta/{id}/ia/auditoria               → fragmento `inteligencia/_auditoria.html.twig` (rodapé do drawer)
 *   GET  /pasta/{id}/ia/analises/{analiseId}.txt → a análise CONCLUÍDA em text/plain, como anexo (404 se não houver)
 *   POST …/{analiseId}/lida | /excluir | /interna → XHR: JSON · form comum: redirect para a pasta #push
 * CSRF: `_token` (campo ou header X-CSRF-Token); ids `inteligencia_push_{pastaId}` e
 * `inteligencia_analise_{analiseId}`.
 */
#[Route('/pasta/{id}/ia', requirements: ['id' => '\d+'])]
final class AnalisePushController extends AbstractController
{
    private const MENSAGEM_FALHA = 'Não foi possível gerar a análise agora. Tente novamente.';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly SolicitarResumoDoPushUseCase $solicitar,
        private readonly ListarAnalisesDaPastaUseCase $listar,
        private readonly ConsultarStatusDaAnaliseUseCase $consultarStatus,
        private readonly MarcarAnaliseComoLidaUseCase $marcarLida,
        private readonly ExcluirAnaliseUseCase $excluir,
        private readonly AlternarAnaliseInternaUseCase $alternarInterna,
        private readonly ExportarAnaliseEmTextoUseCase $exportarTexto,
        private readonly ListarAuditoriaDaPastaUseCase $listarAuditoria,
        // Interface (não a classe) pelo mesmo motivo do PoliticaPrivacidadeController: dublê em teste.
        private readonly RateLimiterFactoryInterface $inteligenciaSolicitarLimiter,
    ) {
    }

    #[Route('/push/analises', name: 'inteligencia_push_solicitar', methods: ['POST'])]
    public function solicitar(Pasta $pasta, Request $request): JsonResponse
    {
        [$user, $tenant] = $this->guardar($pasta);

        if (!$this->isCsrfTokenValid('inteligencia_push_' . $pasta->getId(), $this->tokenDe($request))) {
            return $this->recusa('csrf', 'Sessão expirada. Recarregue a página e tente de novo.', Response::HTTP_FORBIDDEN);
        }

        // Por usuário (não por IP): 10 pedidos por minuto já é mais do que um humano faz.
        if (!$this->inteligenciaSolicitarLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->recusa('muitas_solicitacoes', 'Muitos pedidos em sequência. Aguarde um instante.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $saida = $this->solicitar->executar(new SolicitarResumoDoPushInput((int) $pasta->getId()), $user, $tenant);
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

    #[Route('/push/analises', name: 'inteligencia_push_listar', methods: ['GET'])]
    public function listar(Pasta $pasta): Response
    {
        [, $tenant] = $this->guardar($pasta);

        return $this->render('inteligencia/_analises_push.html.twig', [
            'analisesIa' => $this->listar->executar($pasta, $tenant),
            'pastaId' => (int) $pasta->getId(),
        ]);
    }

    /**
     * Rodapé "Registro de auditoria (N)" do drawer (desenho, dc L1030-1035): a trilha de IA desta
     * pasta, lida de `inteligencia_analise` com tenant explícito. Mesma guarda das outras rotas.
     */
    #[Route('/auditoria', name: 'inteligencia_auditoria_da_pasta', methods: ['GET'])]
    public function auditoria(Pasta $pasta): Response
    {
        [, $tenant] = $this->guardar($pasta);

        return $this->render('inteligencia/_auditoria.html.twig', [
            'auditoria' => $this->listarAuditoria->executar($pasta, $tenant),
            'pastaId' => (int) $pasta->getId(),
        ]);
    }

    #[Route('/analises/{analiseId}', name: 'inteligencia_analise_status', requirements: ['analiseId' => '\d+'], methods: ['GET'])]
    public function status(Pasta $pasta, int $analiseId): JsonResponse
    {
        [$user, $tenant] = $this->guardar($pasta);

        try {
            $saida = $this->consultarStatus->executar($analiseId, (int) $pasta->getId(), $tenant);
        } catch (AnaliseNaoEncontradaException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        $podeVerMotivoTecnico = $this->permissionChecker->canAdminister($user, $tenant, 'admin.inteligencia.manage');

        return new JsonResponse($saida->paraJson() + [
            'mensagem' => match ($saida->status) {
                'falhou' => self::MENSAGEM_FALHA,
                'indisponivel' => Disponibilidade::NaoConfiguradaNaPlataforma->mensagem(),
                default => null,
            },
            'motivoTecnico' => $podeVerMotivoTecnico ? $saida->erroMotivo : null,
        ]);
    }

    /**
     * "Baixar .txt" do cartão (desenho, dc L1001-1003). Mesma guarda das outras rotas por id:
     * outro escritório ou outra pasta → 404. O corpo é texto do modelo: `text/plain` + `nosniff`
     * para o navegador nunca o interpretar como HTML, e `no-store` porque é conteúdo processual.
     */
    #[Route('/analises/{analiseId}.txt', name: 'inteligencia_analise_txt', requirements: ['analiseId' => '\d+'], methods: ['GET'])]
    public function baixarTexto(Pasta $pasta, int $analiseId): Response
    {
        [, $tenant] = $this->guardar($pasta);

        try {
            $saida = $this->exportarTexto->executar($analiseId, $pasta, $tenant);
        } catch (AnaliseNaoEncontradaException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        $resposta = new Response($saida->conteudo, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $saida->nomeDoArquivo),
        ]);
        $resposta->setPrivate();
        $resposta->headers->addCacheControlDirective('no-store');

        return $resposta;
    }

    #[Route('/analises/{analiseId}/lida', name: 'inteligencia_analise_lida', requirements: ['analiseId' => '\d+'], methods: ['POST'])]
    public function lida(Pasta $pasta, int $analiseId, Request $request): Response
    {
        [$user, $tenant] = $this->guardar($pasta);
        $this->exigirCsrfDaAnalise($request, $analiseId);

        try {
            $saida = $this->marcarLida->executar($analiseId, (int) $pasta->getId(), $user, $tenant);
        } catch (AnaliseNaoEncontradaException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        return $this->respostaDaAcao($request, $pasta, $saida->paraJson() + ['lida' => $saida->lida]);
    }

    #[Route('/analises/{analiseId}/excluir', name: 'inteligencia_analise_excluir', requirements: ['analiseId' => '\d+'], methods: ['POST'])]
    public function excluir(Pasta $pasta, int $analiseId, Request $request): Response
    {
        [$user, $tenant] = $this->guardar($pasta);
        $this->exigirCsrfDaAnalise($request, $analiseId);

        try {
            $this->excluir->executar($analiseId, (int) $pasta->getId(), $user, $tenant);
        } catch (AnaliseNaoEncontradaException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        return $this->respostaDaAcao($request, $pasta, ['id' => $analiseId, 'excluida' => true]);
    }

    #[Route('/analises/{analiseId}/interna', name: 'inteligencia_analise_interna', requirements: ['analiseId' => '\d+'], methods: ['POST'])]
    public function interna(Pasta $pasta, int $analiseId, Request $request): Response
    {
        [, $tenant] = $this->guardar($pasta);
        $this->exigirCsrfDaAnalise($request, $analiseId);

        try {
            $saida = $this->alternarInterna->executar($analiseId, (int) $pasta->getId(), $tenant);
        } catch (AnaliseNaoEncontradaException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        return $this->respostaDaAcao($request, $pasta, $saida->paraJson() + ['internaDoEscritorio' => $saida->internaDoEscritorio]);
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

    private function tokenDe(Request $request): string
    {
        $token = $request->request->get('_token');
        if (is_string($token) && $token !== '') {
            return $token;
        }

        return (string) $request->headers->get('X-CSRF-Token', '');
    }

    private function exigirCsrfDaAnalise(Request $request, int $analiseId): void
    {
        if (!$this->isCsrfTokenValid('inteligencia_analise_' . $analiseId, $this->tokenDe($request))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }
    }

    private function recusa(string $motivo, string $mensagem, int $status): JsonResponse
    {
        return new JsonResponse(['motivo' => $motivo, 'mensagem' => $mensagem], $status);
    }

    /** @param array<string, mixed> $json */
    private function respostaDaAcao(Request $request, Pasta $pasta, array $json): Response
    {
        if ($request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json') {
            return new JsonResponse($json);
        }

        return $this->redirect($this->generateUrl('pasta_show', ['id' => $pasta->getId()]) . '#push');
    }
}
