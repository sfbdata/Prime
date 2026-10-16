<?php

declare(strict_types=1);

namespace App\Master\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Editar Perfil: Master do Sistema" (desenho 05 - Perfis 1.0): abas Painel do sistema | Permissões.
 * Exclusivo do Master (ROLE_SUPER_ADMIN). Spec: docs/specs/perfis-master-1.0.md §4.3.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class PainelMasterController extends AbstractController
{
    #[Route('/master', name: 'master_painel', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('master/painel.html.twig');
    }
}
