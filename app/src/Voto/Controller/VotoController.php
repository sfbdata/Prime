<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * BlueJus Voto, tela da equipe logada (desenho docs/design/redesign-08-11-26/18 - Voto - VS 08.11.26.dc.html,
 * spec docs/specs/bluejus-voto.md). Fase A1: a tela dentro da moldura do sistema, com a faixa de demonstração e as
 * quatro abas do desenho; o conteúdo de cada aba chega nos PRs seguintes (seção 6 da spec).
 *
 * Duas travas: a chave `voto` do catálogo do Master (o guarda de rota devolve "em preparação" enquanto estiver
 * desligada, e só o Master passa) e a permissão `modules.voto.view`.
 */
#[Route('/voto')]
final class VotoController extends AbstractController
{
    private const MODULO = 'voto';

    /** Abas do desenho, na ordem da barra: Votar, Resultados, Gestão eleitoral, Acompanhar. */
    public const ABAS = ['ele', 'tra', 'ges', 'aco'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('', name: 'voto_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAcesso();
        $aba = (string) $request->query->get('aba', 'ele');

        return $this->render('voto/index.html.twig', [
            'aba' => in_array($aba, self::ABAS, true) ? $aba : 'ele',
        ]);
    }

    private function assertAcesso(): void
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO)) {
            throw $this->createAccessDeniedException();
        }
    }
}
