<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\BuscaNoPushMuitoFrequenteException;
use App\Pasta\Exception\ProcessoForaDaPastaException;
use App\Pasta\UseCase\AlternarMonitoramentoDaPastaUseCase;
use App\Pasta\UseCase\BuscarAgoraNoPushUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ações do cartão da coluna direita da aba Push (desenho 1.2.3, dc L.2568-2626; spec
 * push-painel-monitoramento §1 e §2): os switches do monitoramento (um por processo da pasta; o
 * campo `processo` diz qual, e processo de fora da pasta dá 404) e o "Buscar agora".
 *
 * Guarda, nas duas: a pasta é do escritório da sessão (404, nunca 403, para não revelar que existe:
 * o resolver busca por PK e o TenantFilter não se aplica a find()), a permissão sobre a pasta (403)
 * e o CSRF (403). Pausar muda o processo, que é dado do caso: exige EDITAR a pasta. Buscar só
 * consulta o diário e grava o que ele publicou: VER a pasta basta, como no "marcar como lida".
 * Na pasta excluída (lápide) as duas são recusadas pelo PastaSomenteLeituraListener (são POST).
 */
#[Route('/pasta')]
final class PastaPushPainelController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/{id}/push/monitoramento', name: 'pasta_push_monitoramento', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function alternarMonitoramento(Pasta $pasta, Request $request, AlternarMonitoramentoDaPastaUseCase $alternar): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $recusa = $this->recusa($pasta, $usuario, 'edit', 'pasta_push_monitoramento_', $request);
        if ($recusa !== null) {
            return $recusa;
        }

        $processoId = $request->request->getInt('processo');
        try {
            $painel = $alternar->executar($pasta, $processoId, $usuario, $this->tenant());
        } catch (ProcessoForaDaPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
        $processo = $painel->processo($processoId);

        return $this->json([
            'sucesso'   => true,
            'ativo'     => $painel->ativo,
            'statusTxt' => $painel->statusTxt,
            'statusSub' => $painel->statusSub,
            'processo'  => ['id' => $processoId, 'principal' => $processo?->principal, 'ativo' => $processo?->ativo, 'sub' => $processo?->sub, 'dica' => $processo?->dicaPausa],
        ]);
    }

    #[Route('/{id}/push/buscar', name: 'pasta_push_buscar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function buscarAgora(Pasta $pasta, Request $request, BuscarAgoraNoPushUseCase $buscar): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $recusa = $this->recusa($pasta, $usuario, 'view', 'pasta_push_buscar_', $request);
        if ($recusa !== null) {
            return $recusa;
        }

        try {
            $busca = $buscar->executar($pasta, $this->tenant());
        } catch (BuscaNoPushMuitoFrequenteException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // A página recarrega a aba depois da busca: o aviso vai como flash, e no JSON para quem chamou.
        $this->addFlash($busca->tom, $busca->mensagem);

        return $this->json(['sucesso' => true, 'novas' => $busca->novas, 'mensagem' => $busca->mensagem]);
    }

    /** Guarda comum: escritório (404), permissão sobre a pasta (403) e CSRF (403). */
    private function recusa(Pasta $pasta, User $usuario, string $acao, string $prefixoDoToken, Request $request): ?JsonResponse
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, 'pasta', (int) $pasta->getId(), $acao)) {
            return $this->json(['erro' => 'Sem permissão para esta ação na pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid($prefixoDoToken . $pasta->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        \assert($tenant instanceof Tenant);

        return $tenant;
    }
}
