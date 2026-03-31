<?php

namespace App\Controller;

use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Auth\User;
use App\Form\EditUserTenantRoleType;
use App\Form\SedeType;
use App\Form\TenantType;
use App\Form\TenantNameType;
use App\Form\TenantPasswordType;
use App\Repository\ClienteRepository;
use App\Repository\PastaRepository;
use App\Repository\ProcessoRepository;
use App\Repository\ResourceAccessRepository;
use App\Repository\SedeRepository;
use App\Repository\TenantRepository;
use App\Repository\TenantRoleRepository;
use App\Service\InvitationService;
use App\Service\PermissionChecker;
use App\Service\TenantBootstrapService;
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
    public function index(TenantRepository $tenantRepository, PermissionChecker $permissionChecker): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException('Você precisa estar logado.');
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $tenants = $tenantRepository->findAll();
        } elseif ($permissionChecker->canAdminister($user, 'admin.tenant.settings.manage')) {
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
        InvitationService $invitationService,
        TenantBootstrapService $tenantBootstrap
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
            $adminUser->setRoles(['ROLE_USER']);
            $adminUser->setTenant($tenant);
            $adminUser->setFullName('Administrador do Tenant');

            // Bootstrap: cria perfil "Administrador do Escritório" e vincula o criador
            $tenantBootstrap->bootstrap($tenant, $adminUser);

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
    public function show(Tenant $tenant, PermissionChecker $permissionChecker): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return $this->render('tenant/show.html.twig', ['tenant' => $tenant]);
        }

        if (!$permissionChecker->canAdminister($user, 'admin.tenant.settings.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para ver este Tenant.');
        }

        if ($user->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Você não tem permissão para acessar este Tenant.');
        }

        return $this->render('tenant/show.html.twig', ['tenant' => $tenant]);
    }

    #[Route('/{id}/edit', name: 'app_tenant_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Tenant $tenant,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        PermissionChecker $permissionChecker,
        TenantRoleRepository $tenantRoleRepository
    ): Response {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $isOwnTenant  = $user->getTenant()?->getId() === $tenant->getId();

        if (!($isSuperAdmin || ($isOwnTenant && $permissionChecker->canAdminister($user, 'admin.tenant.settings.manage')))) {
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
                // Busca o usuário com perfil de sistema (Administrador do Escritório) no tenant
                $adminRole = null;
                foreach ($tenantRoleRepository->findByTenantId($tenant->getId()) as $role) {
                    if ($role->isSystem()) {
                        $adminRole = $role;
                        break;
                    }
                }

                $adminUser = $adminRole
                    ? $entityManager->getRepository(User::class)->findOneBy(['tenantRole' => $adminRole])
                    : null;

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
    public function listUsers(
        Tenant $tenant,
        PermissionChecker $permissionChecker,
        ResourceAccessRepository $resourceAccessRepository,
        ClienteRepository $clienteRepository,
        PastaRepository $pastaRepository,
        ProcessoRepository $processoRepository,
    ): Response {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $isOwnTenant  = $user->getTenant()?->getId() === $tenant->getId();

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para ver os usuários deste Tenant.');
        }

        $users = $tenant->getUsers()->toArray();

        // Busca todos os ResourceAccess dos usuários do tenant em uma query só
        $accessByUser = $resourceAccessRepository->findByUsers($users);

        // Resolve labels dos recursos referenciados (sem N+1)
        $resourceLabels = $this->resolveResourceLabels($accessByUser, $clienteRepository, $pastaRepository, $processoRepository);

        return $this->render('tenant/users.html.twig', [
            'tenant'         => $tenant,
            'users'          => $users,
            'accessByUser'   => $accessByUser,
            'resourceLabels' => $resourceLabels,
        ]);
    }

    /**
     * Resolve os labels exibíveis de cada recurso referenciado nos acessos.
     * Faz no máximo 3 queries (uma por tipo: cliente, pasta, processo).
     *
     * @param  array<int, \App\Entity\Permission\ResourceAccess[]> $accessByUser
     * @return array<string, array<int, string>>  ['cliente' => [id => label], ...]
     */
    private function resolveResourceLabels(
        array $accessByUser,
        ClienteRepository $clienteRepository,
        PastaRepository $pastaRepository,
        ProcessoRepository $processoRepository,
    ): array {
        $idsByType = ['cliente' => [], 'pasta' => [], 'processo' => []];

        foreach ($accessByUser as $rows) {
            foreach ($rows as $ra) {
                $type = $ra->getResourceType();
                $id   = $ra->getResourceId();
                if (isset($idsByType[$type]) && $id !== null) {
                    $idsByType[$type][$id] = true;
                }
            }
        }

        $labels = ['cliente' => [], 'pasta' => [], 'processo' => []];

        foreach (array_keys($idsByType['cliente']) as $id) {
            $entity = $clienteRepository->find($id);
            $labels['cliente'][$id] = $entity?->getNomeExibicao() ?? "Cliente #$id";
        }

        foreach (array_keys($idsByType['pasta']) as $id) {
            $entity = $pastaRepository->find($id);
            $labels['pasta'][$id] = $entity?->getNup() ?? "Pasta #$id";
        }

        foreach (array_keys($idsByType['processo']) as $id) {
            $entity = $processoRepository->find($id);
            $labels['processo'][$id] = $entity?->getNumeroProcesso() ?? "Processo #$id";
        }

        return $labels;
    }

        #[Route('/{tenantId}/user/{id}/edit-role', name: 'app_tenant_user_edit_role', methods: ['GET','POST'])]
    public function editUserRole(
        int $tenantId,
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TenantRoleRepository $tenantRoleRepository,
        ResourceAccessRepository $resourceAccessRepository,
        ClienteRepository $clienteRepository,
        PastaRepository $pastaRepository,
        ProcessoRepository $processoRepository,
        PermissionChecker $permissionChecker
    ): Response {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $currentUser->getTenant()?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar perfis de usuário.');
        }

        // Para SUPER_ADMIN editando outro tenant, buscar roles do tenant do usuário alvo.
        // Para admin normal, buscar roles do próprio tenant.
        $targetTenantId = $isSuperAdmin ? ($user->getTenant()?->getId() ?? $tenantId) : $tenantId;
        $tenantRoles    = $tenantRoleRepository->findByTenantId($targetTenantId);

        $form = $this->createForm(EditUserTenantRoleType::class, $user, [
            'tenant_roles' => $tenantRoles,
        ]);
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

        $userAccesses   = $resourceAccessRepository->findByUsers([$user])[(int) $user->getId()] ?? [];
        $resourceLabels = $this->resolveResourceLabels(
            [(int) $user->getId() => $userAccesses],
            $clienteRepository,
            $pastaRepository,
            $processoRepository,
        );

        return $this->render('tenant/edit_user_role.html.twig', [
            'form'           => $form->createView(),
            'user'           => $user,
            'tenantId'       => $tenantId,
            'userAccesses'   => $userAccesses,
            'resourceLabels' => $resourceLabels,
        ]);
    }

    #[Route('/{tenantId}/user/{userId}/resource-access/{raId}/remove', name: 'app_tenant_user_resource_access_remove', methods: ['POST'])]
    public function removeResourceAccess(
        int $tenantId,
        int $userId,
        int $raId,
        Request $request,
        EntityManagerInterface $entityManager,
        ResourceAccessRepository $resourceAccessRepository,
        PermissionChecker $permissionChecker
    ): Response {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $currentUser->getTenant()?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para remover acessos.');
        }

        if (!$this->isCsrfTokenValid('remove_resource_access_' . $raId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $resourceAccess = $resourceAccessRepository->find($raId);

        // Garante que o acesso pertence ao usuário correto do tenant
        if ($resourceAccess === null || (int) $resourceAccess->getUser()?->getId() !== $userId) {
            throw $this->createNotFoundException('Acesso não encontrado.');
        }

        $entityManager->remove($resourceAccess);
        $entityManager->flush();

        $this->addFlash('success', 'Acesso específico removido com sucesso.');

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $userId,
        ]);
    }

    #[Route('/{id}/sedes', name: 'app_tenant_sedes', methods: ['GET', 'POST'])]
    public function manageSedes(
        Tenant $tenant,
        Request $request,
        EntityManagerInterface $entityManager,
        SedeRepository $sedeRepository,
        PermissionChecker $permissionChecker
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $isOwnTenant  = $user->getTenant()?->getId() === $tenant->getId();

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, 'admin.ponto.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para gerenciar sedes.');
        }

        $sede = new Sede();
        $form = $this->createForm(SedeType::class, $sede);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sede->setTenant($tenant);

            // Converte campo ssidsAutorizados de string para array
            $ssidsInput = $form->get('ssidsAutorizados')->getData();
            if (is_string($ssidsInput) && trim($ssidsInput) !== '') {
                $ssids = array_values(array_filter(array_map('trim', explode(',', $ssidsInput))));
                $sede->setSsidsAutorizados($ssids);
            }

            $entityManager->persist($sede);
            $entityManager->flush();

            $this->addFlash('success', 'Sede cadastrada com sucesso!');
            return $this->redirectToRoute('app_tenant_sedes', ['id' => $tenant->getId()]);
        }

        $sedes = $sedeRepository->findBy(['tenant' => $tenant]);

        return $this->render('tenant/sedes.html.twig', [
            'tenant' => $tenant,
            'sedes'  => $sedes,
            'form'   => $form->createView(),
        ]);
    }

    #[Route('/{tenantId}/sedes/{sedeId}/delete', name: 'app_tenant_sede_delete', methods: ['POST'])]
    public function deleteSede(
        int $tenantId,
        int $sedeId,
        Request $request,
        EntityManagerInterface $entityManager,
        SedeRepository $sedeRepository,
        PermissionChecker $permissionChecker
    ): Response {
        /** @var \App\Entity\Auth\User $user */
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $isOwnTenant  = $user->getTenant()?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, 'admin.ponto.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para excluir sedes.');
        }

        if (!$this->isCsrfTokenValid('delete_sede_' . $sedeId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $sede = $sedeRepository->find($sedeId);

        if ($sede && $sede->getTenant()?->getId() === $tenantId) {
            $entityManager->remove($sede);
            $entityManager->flush();
            $this->addFlash('success', 'Sede removida com sucesso.');
        }

        return $this->redirectToRoute('app_tenant_sedes', ['id' => $tenantId]);
    }

}
