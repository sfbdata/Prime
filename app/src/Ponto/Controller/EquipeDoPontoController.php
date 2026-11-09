<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Service\GestaoDoPonto;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aba "Equipe" da tela /ponto (spec `docs/specs/ponto-desenho-0811.md`, E5; desenho 16 L210 a L219). O painel é buscado
 * pelo js/ponto-paineis.js na primeira vez que a aba abre (`data-carregar` no `_abas.html.twig`).
 *
 * Quem vê: `GestaoDoPonto::veEquipe` no escritório da sessão (P58). Sem ele, 403.
 */
#[Route('/ponto/equipe')]
final class EquipeDoPontoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissoes,
        private readonly GestaoDoPonto $gestao,
    ) {
    }

    #[Route('', name: 'ponto_equipe', methods: ['GET'])]
    public function painel(): Response
    {
        $this->escritorioDoGestor();

        return $this->render('ponto/_abas_equipe.html.twig');
    }

    /** O escritório da sessão, se quem pede gere a equipe dele. */
    private function escritorioDoGestor(): Tenant
    {
        $eu = $this->getUser();
        if (!$eu instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $escritorio = $this->tenantContext->getCurrentTenant();
        if ($escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto') || !$this->gestao->veEquipe($eu, $escritorio)) {
            throw $this->createAccessDeniedException('Sem acesso à equipe do ponto.');
        }

        return $escritorio;
    }
}
