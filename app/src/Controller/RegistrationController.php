<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;

// Rota legada — redirecionada para o novo fluxo de aceite de convites (5b.3a)
class RegistrationController extends AbstractController
{
    #[Route('/register/confirm/{token}', name: 'register_confirm')]
    public function confirm(string $token): RedirectResponse
    {
        return $this->redirectToRoute('auth_aceite_convite', ['token' => $token], 302);
    }
}
