<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
final class PlatformDashboardController extends AbstractController
{
    #[Route('/admin/platform', name: 'admin_platform_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/platform/dashboard.html.twig');
    }
}
