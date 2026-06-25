<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Entity\Tenant\Tenant;
use App\Service\Tenant\TenantContext;
use App\Tenant\UseCase\SairDoEscritorioUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EscritorioController extends AbstractController
{
    public function __construct(
        private readonly SairDoEscritorioUseCase $sairDoEscritorio,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/escritorio/{id}/sair', name: 'escritorio_sair', methods: ['POST'])]
    public function sair(Tenant $tenant, Request $request): Response
    {
        $usuario = $this->getUser();
        if ($usuario === null) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('sair_' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        try {
            $this->sairDoEscritorio->executar($usuario, $tenant);

            if ($this->tenantContext->getCurrentTenant()?->getId() === $tenant->getId()) {
                $this->tenantContext->clearCurrentTenant();
            }

            $this->addFlash('success', 'Você saiu do escritório.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('tenant_selecionar');
    }
}
