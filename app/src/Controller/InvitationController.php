<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;

// Rota legada — TODO 5b.3b: atualizar destino para a nova rota de criação de convites
class InvitationController extends AbstractController
{
    #[Route('/invite', name: 'invite_user')]
    public function invite(): RedirectResponse
    {
        return $this->redirectToRoute('homepage', [], 302);
    }
}
