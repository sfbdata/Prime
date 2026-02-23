<?php

namespace App\Controller;

use App\Entity\Auth\User;
use App\Form\InvitationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class InvitationController extends AbstractController
{
    #[Route('/invite', name: 'invite_user')]
    public function invite(
        Request $request,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $user = new User();
        $form = $this->createForm(InvitationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Gerar token único
            $token = bin2hex(random_bytes(32));
            $user->setInvitationToken($token);
            $user->setIsActive(false);

            // Roles padrão
            if (empty($user->getRoles())) {
                $user->setRoles(['ROLE_USER']);
            }

            // Associar ao mesmo tenant do admin logado
            $currentTenant = $this->getUser()->getTenant();
            $user->setTenant($currentTenant);

            $em->persist($user);
            $em->flush();

            // Gerar link absoluto para confirmação
            $link = $urlGenerator->generate(
                'register_confirm',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Enviar email de convite
            $email = (new Email())
                ->from('jusprime.samuel@gmail.com')
                ->to($user->getEmail())
                ->subject('Convite para acessar o sistema')
                ->text("Olá {$user->getFullName()},\n\nVocê foi convidado para acessar o sistema.\nClique no link abaixo para criar sua senha:\n\n$link");

            $mailer->send($email);

            $this->addFlash('success', 'Convite enviado com sucesso!');
            return $this->redirectToRoute('homepage');
        }

        return $this->render('invitation/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
