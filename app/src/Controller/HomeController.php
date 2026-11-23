<?php

namespace App\Controller;

use App\Produto\Service\PaginaInicialDoEscritorio;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly PaginaInicialDoEscritorio $paginaInicial,
        private readonly TenantContext $tenantContext,
    ) {}

    /** A tela inicial do escritório da sessão (multiproduto, §5): o Expediente no completo; sem escritório, idem. */
    #[Route('/inicio', name: 'homepage')]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute($this->paginaInicial->rotaOuPadrao($this->tenantContext->getCurrentTenant()));
    }
}
