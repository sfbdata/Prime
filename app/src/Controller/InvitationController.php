<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;

// Rota legada — redireciona para a tela de gerenciamento de convites do escritório
class InvitationController extends AbstractController
{
    #[Route('/invite', name: 'invite_user')]
    public function invite(): RedirectResponse
    {
        return $this->redirectToRoute('auth_gerenciar_convites', [], 302);
    }
}
