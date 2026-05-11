<?php
declare(strict_types=1);

namespace App\Controller\Tenant;

use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TenantSelecaoController extends AbstractController
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/escritorio/selecionar', name: 'tenant_selecionar', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_selecionar', $request->request->getString('_csrf_token'))) {
                throw $this->createAccessDeniedException('Token CSRF inválido.');
            }

            $tenantId = $request->request->getInt('tenant_id');
            if ($tenantId <= 0) {
                $this->addFlash('danger', 'Selecione um escritório válido.');

                return $this->redirectToRoute('tenant_selecionar');
            }

            try {
                $this->tenantContext->setCurrentTenant($tenantId);
            } catch (\Throwable) {
                $this->addFlash('danger', 'Você não tem acesso a este escritório.');

                return $this->redirectToRoute('tenant_selecionar');
            }

            return $this->redirectToRoute('expediente_index');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $vinculos = $this->userTenantRepository->findActiveByUser($user);

        if (count($vinculos) === 1) {
            $this->tenantContext->setCurrentTenant($vinculos[0]->getTenant()->getId());

            return $this->redirectToRoute('expediente_index');
        }

        if (count($vinculos) === 0 && $this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirectToRoute('admin_platform_dashboard');
        }

        return $this->render('tenant/selecionar.html.twig', [
            'vinculos' => $vinculos,
        ]);
    }
}
