<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\RegraDosMasters;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Editar Perfil: Master do Sistema" (desenho 05 - Perfis 1.2): abas Painel do sistema | Permissões.
 * Exclusivo do Master (ROLE_SUPER_ADMIN). Specs: docs/specs/perfis-master-1.0.md §4.3 e
 * docs/specs/perfis-1.2-masters.md §4.1 (Masters primário e secundários).
 *
 * A página só monta a moldura e a aba Permissões; as seções do painel chegam pelos endpoints JSON
 * de {@see PainelMasterDadosController}. Promover e remover Master: {@see MastersDoSistemaController}.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class PainelMasterController extends AbstractController
{
    public function __construct(
        private readonly PainelMasterRepository $repositorio,
        private readonly RegraDosMasters $regraDosMasters,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/master', name: 'master_painel', methods: ['GET'])]
    public function __invoke(): Response
    {
        $ator = $this->getUser();
        $souPrimario = $ator instanceof User && $this->regraDosMasters->ehMasterPrimario($ator);
        $escritorio = $souPrimario ? $this->tenantContext->getCurrentTenant() : null;

        return $this->render('master/painel.html.twig', [
            'masters'     => $this->repositorio->masters(),
            'souPrimario' => $souPrimario,
            // M5: só secundários do escritório em que o Master primário está.
            'candidatos'  => $escritorio !== null ? $this->repositorio->candidatosAMaster((int) $escritorio->getId()) : [],
        ]);
    }
}
