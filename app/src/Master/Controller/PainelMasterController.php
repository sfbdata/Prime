<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Master\Repository\PainelMasterRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Editar Perfil: Master do Sistema" (desenho 05 - Perfis 1.0): abas Painel do sistema | Permissões.
 * Exclusivo do Master (ROLE_SUPER_ADMIN). Spec: docs/specs/perfis-master-1.0.md §4.3.
 *
 * A página só monta a moldura e a aba Permissões; as seções do painel chegam pelos endpoints JSON
 * de {@see PainelMasterDadosController}.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class PainelMasterController extends AbstractController
{
    public function __construct(
        private readonly PainelMasterRepository $repositorio,
    ) {
    }

    #[Route('/master', name: 'master_painel', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('master/painel.html.twig', [
            'masters' => $this->repositorio->masters(),
        ]);
    }
}
