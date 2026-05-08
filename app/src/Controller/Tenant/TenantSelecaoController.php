<?php
declare(strict_types=1);

namespace App\Controller\Tenant;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TenantSelecaoController extends AbstractController
{
    #[Route('/escritorio/selecionar', name: 'tenant_selecionar')]
    public function __invoke(): Response
    {
        return $this->render('tenant/selecionar.html.twig');
    }
}
