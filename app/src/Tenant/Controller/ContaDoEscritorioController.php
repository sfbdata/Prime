<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Tenant\UseCase\ListarMinhasContasUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Modal "Conta do escritório" (desenho bj-contas.js, VS 18.10.26). A aba "Trocar conta" é
 * carregada só quando o modal abre: a base não paga a contagem de equipe em toda página.
 * Qualquer usuário logado; a lista sai dos vínculos da própria pessoa.
 */
final class ContaDoEscritorioController extends AbstractController
{
    public function __construct(
        private readonly ListarMinhasContasUseCase $listarMinhasContas,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/escritorio/contas', name: 'escritorio_contas', methods: ['GET'])]
    public function trocar(): Response
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $atual = $this->tenantContext->getCurrentTenant();

        return $this->render('menu_usuario/_trocar_conta.html.twig', [
            'contas' => $this->listarMinhasContas->executar($usuario, $atual?->getId()),
        ]);
    }
}
