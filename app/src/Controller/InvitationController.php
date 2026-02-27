<?php

namespace App\Controller;

use App\Entity\Auth\User;
use App\Form\InvitationType;
use App\Service\InvitationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class InvitationController extends AbstractController
{
    #[Route('/invite', name: 'invite_user')]
    public function invite(
        Request $request,
        EntityManagerInterface $entityManager,
        InvitationService $invitationService
    ): Response {
        $user = new User();
        $form = $this->createForm(InvitationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim((string) $user->getEmail()));
            $user->setEmail($email);

            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingUser) {
                $form->get('email')->addError(new FormError('Este e-mail já está cadastrado. Informe outro e-mail para enviar o convite.'));

                return $this->render('invitation/index.html.twig', [
                    'form' => $form->createView(),
                ]);
            }

            if (empty($user->getRoles())) {
                $user->setRoles(['ROLE_USER']);
            }

            $currentTenant = $this->getUser()->getTenant();
            $user->setTenant($currentTenant);

            $result = $invitationService->sendInvitation($user);

            if ($result['duplicateEmail']) {
                $form->get('email')->addError(new FormError('Este e-mail já está cadastrado. Informe outro e-mail para enviar o convite.'));

                return $this->render('invitation/index.html.twig', [
                    'form' => $form->createView(),
                ]);
            }

            if (!$result['sent']) {
                $this->addFlash('warning', 'Usuário criado, mas não foi possível enviar o e-mail de convite agora. Verifique a configuração SMTP e tente novamente.');

                if ($this->getParameter('kernel.environment') === 'dev') {
                    $this->addFlash('info', sprintf('Link de confirmação (dev): %s', $result['link']));
                }

                return $this->redirectToRoute('homepage');
            }

            $this->addFlash('success', 'Convite enviado com sucesso!');
            return $this->redirectToRoute('homepage');
        }

        return $this->render('invitation/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
