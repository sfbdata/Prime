<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\UseCase\ObterRankingDoColaboradorUseCase;
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
 * Cartão do colaborador do Dashboard (botão direito no nome; spec dashboard-parte-1, item 4).
 *
 * GET, somente leitura, JSON. Query: `data_de`/`data_ate` do painel (o cartão abre nesse período) e,
 * opcional, `janela` (mes, trimestre, semestre, ano), a alternativa do seletor. Guardas: as do Dashboard (escritório na sessão + módulo `bi` → 403) e
 * a posse: o id tem de ser colaborador ativo do escritório corrente, senão 404 (IDOR).
 */
#[Route('/dashboard')]
final class RankingDoDashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ObterRankingDoColaboradorUseCase $obterRanking,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    #[Route('/colaborador/{id}/ranking', name: 'dashboard_colaborador_ranking', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function ranking(int $id, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);

        // Cadeado (spec pasta-cadeado D1): meta e registro de pasta oculta para quem olha não contam.
        $pastasOcultas = $this->acessoAPasta->idsOcultosPara($usuario, (int) $tenant->getId());
        $janela        = (string) $request->query->get('janela', '');

        try {
            $saida = $this->obterRanking->executar($tenant, $id, $janela, $request->query->all(), new \DateTimeImmutable(), $pastasOcultas);
        } catch (ColaboradorForaDoEscritorioException) {
            throw $this->createNotFoundException('Colaborador não encontrado.');
        }

        return $this->json($saida->paraArray());
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
