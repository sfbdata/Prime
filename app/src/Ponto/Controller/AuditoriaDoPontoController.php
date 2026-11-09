<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Service\GestaoDoPonto;
use App\Ponto\UseCase\ListarTrilhaDoPontoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aba "Auditoria" da tela /ponto (spec `docs/specs/ponto-desenho-0811.md`, E9, P64; desenho 16 L222 a L225): a trilha
 * de auditoria do Ponto do escritório, o mais recente primeiro, com a conferência da cadeia no cabeçalho. O painel é
 * buscado pelo js/ponto-paineis.js na primeira vez que a aba abre (`data-carregar` no `_abas.html.twig`); a troca de
 * página busca de novo com `?pagina=` (js/ponto-auditoria.js).
 *
 * Quem vê: `GestaoDoPonto::veAuditoria` no escritório da sessão (quem gere o ponto pelo perfil, o primário e o
 * Master). Sem ele, 403.
 */
#[Route('/ponto/auditoria')]
final class AuditoriaDoPontoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissoes,
        private readonly GestaoDoPonto $gestao,
    ) {
    }

    #[Route('', name: 'ponto_auditoria', methods: ['GET'])]
    public function painel(Request $request, ListarTrilhaDoPontoUseCase $listar): Response
    {
        $escritorio = $this->escritorioDoGestor();

        return $this->render('ponto/_abas_auditoria.html.twig', [
            'trilha' => $listar->executar($escritorio, $request->query->getInt('pagina', 1)),
        ]);
    }

    /** O escritório da sessão, se quem pede vê a trilha dele. */
    private function escritorioDoGestor(): Tenant
    {
        $eu = $this->getUser();
        if (!$eu instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $escritorio = $this->tenantContext->getCurrentTenant();
        if ($escritorio === null || !$this->permissoes->canAccessModule($eu, $escritorio, 'ponto') || !$this->gestao->veAuditoria($eu, $escritorio)) {
            throw $this->createAccessDeniedException('Sem acesso à auditoria do ponto.');
        }

        return $escritorio;
    }
}
