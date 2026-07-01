<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\Tenant\TenantContext;
use App\Tenant\DTO\CriarEscritorioInput;
use App\Tenant\Form\CriarEscritorioType;
use App\Tenant\UseCase\CriarEscritorioUseCase;
use App\Tenant\UseCase\SairDoEscritorioUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EscritorioController extends AbstractController
{
    public function __construct(
        private readonly SairDoEscritorioUseCase $sairDoEscritorio,
        private readonly CriarEscritorioUseCase $criarEscritorio,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/escritorio/criar', name: 'escritorio_criar', methods: ['GET', 'POST'])]
    public function criar(Request $request): Response
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $exigirOab = $usuario->getOabNumero() === null;
        $input     = new CriarEscritorioInput();
        $form      = $this->createForm(CriarEscritorioType::class, $input, ['exigir_oab' => $exigirOab]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $tenant = $this->criarEscritorio->executar($input, $usuario);
                $this->tenantContext->setCurrentTenant($tenant->getId());
                $this->addFlash('success', 'Escritório criado com sucesso. Você já está dentro dele.');

                return $this->redirectToRoute('expediente_index');
            } catch (\DomainException | \InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('tenant/criar.html.twig', [
            'form'      => $form,
            'exigirOab' => $exigirOab,
        ]);
    }

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
