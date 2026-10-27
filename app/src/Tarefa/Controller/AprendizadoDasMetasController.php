<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Tarefa\UseCase\ApagarAprendizadoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Apagar o que foi aprendido" do card Preferências do Meu Perfil (D4 de Minhas Metas, Fase 4). A pessoa apaga só o
 * que é dela, no escritório da sessão; a confirmação é a do navegador (`data-confirmar`) e o POST leva o CSRF.
 */
final class AprendizadoDasMetasController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/perfil/aprendizado/apagar', name: 'app_profile_aprendizado_apagar', methods: ['POST'])]
    public function apagar(Request $request, ApagarAprendizadoUseCase $useCase): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('apagar_aprendizado', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null) {
            throw $this->createAccessDeniedException('Sem escritório selecionado.');
        }
        $useCase->executar($tenant, $usuario);
        $this->addFlash('success', $this->translator->trans('perfil.preferencias.apagar_aprendizado_feito'));

        return $this->redirectToRoute('app_profile');
    }
}
