<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\UseCase\ObterRelatorioDeRegistrosDoColaboradorUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\AcessoAPasta;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Modal "Ver relatório de X" da coluna Relatório do Dashboard (spec dashboard-parte-1, PR-D).
 *
 * GET, somente leitura, JSON. Query: `data_de`/`data_ate` do painel. Guardas: as do Dashboard
 * (escritório na sessão + módulo `bi` → 403) e a posse: o id tem de ser colaborador ativo do
 * escritório corrente, senão 404 (IDOR). Pasta oculta pelo cadeado não aparece para quem olha (D1).
 */
#[Route('/dashboard')]
final class RelatorioDoDashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ObterRelatorioDeRegistrosDoColaboradorUseCase $obterRelatorio,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    #[Route('/relatorio/{userId}', name: 'dashboard_relatorio', methods: ['GET'], requirements: ['userId' => '\d+'])]
    public function relatorio(int $userId, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);

        $pastasOcultas = $this->acessoAPasta->idsOcultosPara($usuario, (int) $tenant->getId());

        try {
            $saida = $this->obterRelatorio->executar($tenant, $userId, $request->query->all(), $pastasOcultas, $usuario);
        } catch (ColaboradorForaDoEscritorioException) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        }

        return $this->json($saida->paraArray(fn (int $pastaId): string => $this->generateUrl('pasta_show', ['id' => $pastaId])));
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'bi')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Dashboard.');
        }

        return $tenant;
    }
}
