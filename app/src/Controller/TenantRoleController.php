<?php

namespace App\Controller;

use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Form\TenantRoleType;
use App\Repository\TenantRoleRepository;
use App\Repository\PermissionRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/tenant/{tenantId}/roles')]
final class TenantRoleController extends AbstractController
{
    /**
     * Garante que o usuário autenticado tem admin.roles.manage
     * e que o tenantId bate com o tenant do usuário (ou é SUPER_ADMIN).
     */
    private function assertAccess(int $tenantId, PermissionChecker $checker): void
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        if ($isSuperAdmin) {
            return;
        }

        if ($user->getTenant()?->getId() !== $tenantId) {
            throw $this->createAccessDeniedException('Você não tem acesso aos perfis deste escritório.');
        }

        if (!$checker->canAdminister($user, 'admin.roles.manage')) {
            throw $this->createAccessDeniedException('Você não tem permissão para gerenciar perfis.');
        }
    }

    #[Route('', name: 'app_tenant_role_index', methods: ['GET'])]
    public function index(
        int $tenantId,
        TenantRoleRepository $roleRepository,
        PermissionChecker $checker
    ): Response {
        $this->assertAccess($tenantId, $checker);

        $roles = $roleRepository->findByTenantId($tenantId);

        return $this->render('tenant_role/index.html.twig', [
            'tenantId' => $tenantId,
            'roles'    => $roles,
        ]);
    }

    #[Route('/new', name: 'app_tenant_role_new', methods: ['GET', 'POST'])]
    public function new(
        int $tenantId,
        Request $request,
        EntityManagerInterface $em,
        PermissionRepository $permissionRepository,
        PermissionChecker $checker
    ): Response {
        $this->assertAccess($tenantId, $checker);

        $user = $this->getUser();
        $tenant = $user->getTenant();

        // SUPER_ADMIN pode criar perfis para qualquer tenant — busca o tenant pelo ID
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $tenant = $em->getReference(\App\Entity\Tenant\Tenant::class, $tenantId);
        }

        $role = new TenantRole();
        $role->setTenant($tenant);

        $allPermissions = $permissionRepository->findAllGrouped();

        $form = $this->createForm(TenantRoleType::class, $role, [
            'permissions' => $allPermissions,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedCodes = $form->get('permissions')->getData() ?? [];
            $this->syncPermissions($role, $selectedCodes, $permissionRepository, $em);

            $em->persist($role);
            $em->flush();

            $this->addFlash('success', 'Perfil criado com sucesso!');

            return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId]);
        }

        return $this->render('tenant_role/new.html.twig', [
            'tenantId'       => $tenantId,
            'form'           => $form,
            'allPermissions' => $allPermissions,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_tenant_role_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $tenantId,
        TenantRole $role,
        Request $request,
        EntityManagerInterface $em,
        PermissionRepository $permissionRepository,
        PermissionChecker $checker
    ): Response {
        $this->assertAccess($tenantId, $checker);

        // Garante que o perfil pertence ao tenant da URL
        if ($role->getTenant()?->getId() !== $tenantId) {
            throw $this->createNotFoundException('Perfil não encontrado neste escritório.');
        }

        $allPermissions = $permissionRepository->findAllGrouped();

        // Códigos já associados ao perfil (para pré-marcar checkboxes)
        $currentCodes = [];
        foreach ($role->getTenantRolePermissions() as $trp) {
            $currentCodes[] = $trp->getPermission()?->getCode();
        }

        $form = $this->createForm(TenantRoleType::class, $role, [
            'permissions'   => $allPermissions,
            'selected_codes' => $currentCodes,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($role->isSystem() && $form->get('name')->getData() !== $role->getName()) {
                // Permite editar permissões mas não renomear perfis de sistema
                $role->setName($role->getName());
            }

            $selectedCodes = $form->get('permissions')->getData() ?? [];
            $this->syncPermissions($role, $selectedCodes, $permissionRepository, $em);
            $role->setUpdatedAt(new \DateTimeImmutable());

            $em->flush();

            $this->addFlash('success', 'Perfil atualizado com sucesso!');

            return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId]);
        }

        return $this->render('tenant_role/edit.html.twig', [
            'tenantId'       => $tenantId,
            'role'           => $role,
            'form'           => $form,
            'allPermissions' => $allPermissions,
            'currentCodes'   => $currentCodes,
        ]);
    }

    #[Route('/{id}', name: 'app_tenant_role_delete', methods: ['POST'])]
    public function delete(
        int $tenantId,
        TenantRole $role,
        Request $request,
        EntityManagerInterface $em,
        PermissionChecker $checker
    ): Response {
        $this->assertAccess($tenantId, $checker);

        if ($role->getTenant()?->getId() !== $tenantId) {
            throw $this->createNotFoundException('Perfil não encontrado neste escritório.');
        }

        if ($role->isSystem()) {
            $this->addFlash('error', 'Perfis de sistema não podem ser excluídos.');
            return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId]);
        }

        if ($this->isCsrfTokenValid('delete_role_' . $role->getId(), $request->request->get('_token'))) {
            $em->remove($role);
            $em->flush();
            $this->addFlash('success', 'Perfil excluído.');
        }

        return $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId]);
    }

    /**
     * Sincroniza as TenantRolePermissions com os códigos selecionados no form.
     * Remove as não selecionadas e adiciona as novas.
     *
     * @param string[] $selectedCodes
     */
    private function syncPermissions(
        TenantRole $role,
        array $selectedCodes,
        PermissionRepository $permissionRepository,
        EntityManagerInterface $em
    ): void {
        // Remove permissões não selecionadas
        foreach ($role->getTenantRolePermissions() as $trp) {
            $code = $trp->getPermission()?->getCode();
            if (!in_array($code, $selectedCodes, true)) {
                $role->getTenantRolePermissions()->removeElement($trp);
                $em->remove($trp);
            }
        }

        // Códigos já presentes
        $existingCodes = [];
        foreach ($role->getTenantRolePermissions() as $trp) {
            $existingCodes[] = $trp->getPermission()?->getCode();
        }

        // Adiciona as novas
        foreach ($selectedCodes as $code) {
            if (in_array($code, $existingCodes, true)) {
                continue;
            }
            $permission = $permissionRepository->findOneBy(['code' => $code]);
            if (!$permission) {
                continue;
            }
            $trp = new TenantRolePermission();
            $trp->setTenantRole($role);
            $trp->setPermission($permission);
            $em->persist($trp);
            $role->getTenantRolePermissions()->add($trp);
        }
    }
}
