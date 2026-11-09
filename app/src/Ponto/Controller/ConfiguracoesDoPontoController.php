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
 * Aba "Configurações" da tela /ponto (spec `docs/specs/ponto-desenho-0811.md`, E6 em diante; desenho 16 L227 a
 * L260). O painel é buscado pelo js/ponto-paineis.js na primeira vez que a aba abre (`data-carregar` no
 * `_abas.html.twig`).
 *
 * Quem vê: `GestaoDoPonto::veConfiguracoes` no escritório da sessão (P58); cada seção confere a permissão dela.
 * Sem ele, 403.
 */
#[Route('/ponto/configuracoes')]
final class ConfiguracoesDoPontoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissoes,
        private readonly GestaoDoPonto $gestao,
    ) {
    }

    #[Route('', name: 'ponto_configuracoes', methods: ['GET'])]
    public function painel(): Response
    {
        $this->escritorioDoGestor();

        return $this->render('ponto/_abas_configuracoes.html.twig');
    }

    /** O escritório da sessão, se quem pede vê as configurações do ponto dele. */
    private function escritorioDoGestor(): Tenant
    {
        $eu = $this->getUser();
        if (!$eu instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $escritorio = $this->tenantContext->getCurrentTenant();
        if ($escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto') || !$this->gestao->veConfiguracoes($eu, $escritorio)) {
            throw $this->createAccessDeniedException('Sem acesso às configurações do ponto.');
        }

        return $escritorio;
    }
}
