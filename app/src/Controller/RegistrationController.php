<?php

namespace App\Controller;

use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register/confirm/{token}', name: 'register_confirm')]
    public function confirm(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = $em->getRepository(User::class)->findOneBy(['invitationToken' => $token]);

        if (!$user) {
            throw $this->createNotFoundException('Convite inválido ou já utilizado.');
        }

        if ($request->isMethod('POST')) {
            $plainPassword = $request->request->get('password');
            $confirmPassword = $request->request->get('confirm_password');

            // Validações básicas
            if (!$plainPassword) {
                $this->addFlash('error', 'A senha não pode estar vazia.');
                return $this->redirectToRoute('register_confirm', ['token' => $token]);
            }

            if ($plainPassword !== $confirmPassword) {
                $this->addFlash('error', 'As senhas não coincidem.');
                return $this->redirectToRoute('register_confirm', ['token' => $token]);
            }

            // Codificar a senha
            $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);

            // Ativar usuário e limpar token
            $user->setIsActive(true);
            $user->setInvitationToken(null);

            $em->flush();

            $this->addFlash('success', 'Senha criada com sucesso! Agora você pode fazer login.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/confirm.html.twig', [
            'user' => $user,
        ]);
    }
}
