<?php

namespace App\Controller;

use App\Entity\Tenant\Tenant;
use App\Entity\Auth\User;
use App\Form\TenantType;
use App\Form\TenantNameType;
use App\Form\TenantPasswordType;
use App\Repository\TenantRepository;
use App\Service\InvitationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/tenant')]
final class TenantController extends AbstractController
{
    #[Route(name: 'app_tenant_index', methods: ['GET'])]
    public function index(TenantRepository $tenantRepository): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException('Você precisa estar logado.');
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $tenants = $tenantRepository->findAll();
        } elseif (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $tenants = [$user->getTenant()];
        } else {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar Tenants.');
        }

        return $this->render('tenant/index.html.twig', [
            'tenants' => $tenants,
        ]);
    }

    #[Route('/new', name: 'app_tenant_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        InvitationService $invitationService
    ): Response {
        $tenant = new Tenant();
        $form = $this->createForm(TenantType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $adminEmail = mb_strtolower(trim((string) $form->get('adminEmail')->getData()));
            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $adminEmail]);

            if ($existingUser) {
                $form->get('adminEmail')->addError(new FormError('Este e-mail já está em uso. Informe outro e-mail para o administrador.'));

                return $this->render('tenant/new.html.twig', [
                    'tenant' => $tenant,
                    'form' => $form,
                ]);
            }

            $entityManager->persist($tenant);
            $entityManager->flush();

            $adminUser = new User();
            $adminUser->setEmail($adminEmail);
            $adminUser->setRoles(['ROLE_ADMIN']);
            $adminUser->setTenant($tenant);
            $adminUser->setFullName('Administrador do Tenant');

            $result = $invitationService->sendInvitation($adminUser, 'Confirme seu acesso ao Tenant');

            if ($result['duplicateEmail']) {
                $entityManager->remove($tenant);
                $entityManager->flush();

                $form->get('adminEmail')->addError(new FormError('Este e-mail já está cadastrado. Informe outro e-mail para o administrador.'));

                return $this->render('tenant/new.html.twig', [
                    'tenant' => $tenant,
                    'form' => $form,
                ]);
            }

            if (!$result['sent']) {
                $this->addFlash('warning', 'Tenant criado, mas não foi possível enviar o e-mail de ativação do administrador agora. Verifique SMTP e reenvie o convite.');

                if ($this->getParameter('kernel.environment') === 'dev') {
                    $this->addFlash('info', sprintf('Link de confirmação (dev): %s', $result['link']));
                }

                return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
            }

            $this->addFlash('success', 'Tenant criado com sucesso! O administrador receberá um e-mail para criar a senha.');

            return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('tenant/new.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_tenant_show', methods: ['GET'])]
    public function show(Tenant $tenant): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return $this->render('tenant/show.html.twig', ['tenant' => $tenant]);
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            if ($user->getTenant()->getId() !== $tenant->getId()) {
                throw $this->createAccessDeniedException('Você não tem permissão para acessar este Tenant.');
            }
            return $this->render('tenant/show.html.twig', ['tenant' => $tenant]);
        }

        throw $this->createAccessDeniedException('Você não tem permissão para ver este Tenant.');
    }

    #[Route('/{id}/edit', name: 'app_tenant_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Tenant $tenant,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (!(in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) ||
            (in_array('ROLE_ADMIN', $user->getRoles(), true) && $user->getTenant() === $tenant))) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar este Tenant.');
        }

        // Form para editar nome
        $nameForm = $this->createForm(TenantNameType::class, $tenant);
        $nameForm->handleRequest($request);
        if ($nameForm->isSubmitted() && $nameForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Nome do Tenant atualizado com sucesso!');
            return $this->redirectToRoute('app_tenant_edit', ['id' => $tenant->getId()]);
        }

        // Form para alterar senha
        $passwordForm = $this->createForm(TenantPasswordType::class, $tenant);
        $passwordForm->handleRequest($request);
        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $newPassword = $passwordForm->get('password')->getData();
            $confirmPassword = $passwordForm->get('confirm_password')->getData();

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('error', 'As senhas não coincidem.');
            } else {
                $adminUser = $entityManager->getRepository(User::class)
                    ->findOneBy(['tenant' => $tenant, 'roles' => ['ROLE_ADMIN']]);

                if ($adminUser) {
                    $adminUser->setPassword($passwordHasher->hashPassword($adminUser, $newPassword));
                    $entityManager->flush();
                    $this->addFlash('success', 'Senha do administrador alterada com sucesso!');
                }
            }
            return $this->redirectToRoute('app_tenant_edit', ['id' => $tenant->getId()]);
        }

        return $this->render('tenant/edit.html.twig', [
            'tenant' => $tenant,
            'nameForm' => $nameForm->createView(),
            'passwordForm' => $passwordForm->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_tenant_delete', methods: ['POST'])]
    public function delete(Request $request, Tenant $tenant, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            throw $this->createAccessDeniedException('Somente SUPER_ADMIN pode excluir Tenants.');
        }

        if ($this->isCsrfTokenValid('delete'.$tenant->getId(), $request->request->get('_token'))) {
            $entityManager->remove($tenant);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_tenant_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/users', name: 'app_tenant_users', methods: ['GET'])]
    public function listUsers(Tenant $tenant): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        // Apenas SUPER_ADMIN ou ADMIN do Tenant podem ver
        if (!(in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) ||
            (in_array('ROLE_ADMIN', $user->getRoles(), true) && $user->getTenant() === $tenant))) {
            throw $this->createAccessDeniedException('Você não tem permissão para ver os usuários deste Tenant.');
        }

        return $this->render('tenant/users.html.twig', [
            'tenant' => $tenant,
            'users' => $tenant->getUsers(),
        ]);
        
    }

        #[Route('/{tenantId}/user/{id}/edit-role', name: 'app_tenant_user_edit_role', methods: ['GET','POST'])]
    public function editUserRole(
        int $tenantId,
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        if (!(in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true) ||
            (in_array('ROLE_ADMIN', $currentUser->getRoles(), true) && $currentUser->getTenant()->getId() === $tenantId))) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar roles.');
        }

        $form = $this->createForm(\App\Form\UserRolesType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('newPassword')->getData();

            if (is_string($newPassword) && trim($newPassword) !== '') {
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            }

            $entityManager->flush();
            $this->addFlash('success', 'Usuário atualizado com sucesso!');
            return $this->redirectToRoute('app_tenant_users', ['id' => $tenantId]);
        }

        return $this->render('tenant/edit_user_role.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
        ]);
    }

    

}
