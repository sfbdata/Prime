<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\UseCase\ObterHistoricoDoCardUseCase;
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
 * Janela "Histórico" dos cards do Dashboard (botão direito num card — DASH-017).
 *
 * GET, somente leitura, JSON: o modelo de exibição pronto (HistoricoDoCardOutput). Guardas: as
 * mesmas do Dashboard — escritório na sessão + módulo `bi` → 403. O universo de colaboradores é
 * o do card no painel (`responsavel`/`cargo` da tela), sempre dentro do escritório corrente.
 */
#[Route('/dashboard')]
final class HistoricoDoDashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ObterHistoricoDoCardUseCase $obterHistorico,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    #[Route('/historico/{card}', name: 'dashboard_historico', methods: ['GET'], requirements: ['card' => 'pastas|metas|urgentes|global'])]
    public function historico(string $card, Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);

        $filtros = [
            'responsavel' => (string) $request->query->get('responsavel', ''),
            'cargo'       => (string) $request->query->get('cargo', ''),
        ];

        // Cadeado (spec pasta-cadeado D1): a pasta oculta para quem olha não conta, como no card. Uma consulta só.
        $pastasOcultas = $this->acessoAPasta->idsOcultosPara($usuario, (int) $tenant->getId());

        $saida = $this->obterHistorico->executar($tenant, $card, new \DateTimeImmutable(), $filtros, $pastasOcultas);

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
