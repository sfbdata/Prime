<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * O antigo "Painel da Plataforma" virou o Painel do Master (spec perfis-master §5 E2). A rota fica
 * para não quebrar link salvo e redireciona, com a mesma guarda.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class PlatformDashboardController extends AbstractController
{
    #[Route('/admin/platform', name: 'admin_platform_dashboard', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('master_painel');
    }
}
