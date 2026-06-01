<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ObterDadosDashboardUseCase $obterDadosUseCase,
    ) {}

    #[Route('', name: 'dashboard_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->assertAccess($currentUser);
        $output      = $this->obterDadosUseCase->executar($tenant, new \DateTimeImmutable());

        return $this->render('dashboard/index.html.twig', [
            'dashboard' => $output,
        ]);
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
