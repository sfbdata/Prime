<?php

namespace App\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\ResourceAccessRepository;
use App\Repository\UserTenantRepository;

class PermissionChecker
{
    public function __construct(
        private readonly ResourceAccessRepository $resourceAccessRepository,
        private readonly UserTenantRepository $userTenantRepository,
    ) {}

    public function canAccessModule(User $user, ?Tenant $tenant, string $module): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        $userTenant = $this->resolveUserTenant($user, $tenant);

        if ($userTenant?->getTenantRole()?->isSystem() === true) {
            return true;
        }

        if ($userTenant === null) {
            return false;
        }

        return $this->hasPermissionFromUserTenant($userTenant, sprintf('modules.%s.view', $module));
    }

    public function canAdminister(User $user, ?Tenant $tenant, string $permission): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        $userTenant = $this->resolveUserTenant($user, $tenant);

        if ($userTenant?->getTenantRole()?->isSystem() === true) {
            return true;
        }

        if ($userTenant === null) {
            return false;
        }

        return $this->hasPermissionFromUserTenant($userTenant, $permission);
    }

    public function canAccessResource(User $user, ?Tenant $tenant, string $resourceType, int $resourceId, string $action): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        $userTenant = $this->resolveUserTenant($user, $tenant);

        if ($userTenant?->getTenantRole()?->isSystem() === true) {
            return true;
        }

        if ($userTenant === null) {
            return false;
        }

        $resourceAccess = $this->resourceAccessRepository->findForUserAndResource($user, $resourceType, $resourceId);
        if ($resourceAccess !== null && $resourceAccess->allows($action)) {
            return true;
        }

        return $this->hasPermissionFromUserTenant($userTenant, sprintf('resources.%s.%s', $resourceType, $action));
    }

    public function hasPermission(User $user, ?Tenant $tenant, string $permission): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        $userTenant = $this->resolveUserTenant($user, $tenant);

        if ($userTenant?->getTenantRole()?->isSystem() === true) {
            return true;
        }

        if ($userTenant === null) {
            return false;
        }

        return $this->hasPermissionFromUserTenant($userTenant, $permission);
    }

    // -------------------------------------------------------------------------
    // Helpers internos
    // -------------------------------------------------------------------------

    private function isGlobalSuperAdmin(User $user): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    private function resolveUserTenant(User $user, Tenant $tenant): ?UserTenant
    {
        $ut = $this->userTenantRepository->findOneBy(['user' => $user, 'tenant' => $tenant]);

        return ($ut?->isActive() === true) ? $ut : null;
    }

    private function hasPermissionFromUserTenant(UserTenant $userTenant, string $permission): bool
    {
        $tenantRole = $userTenant->getTenantRole();

        if ($tenantRole === null) {
            return false;
        }

        foreach ($tenantRole->getTenantRolePermissions() as $tenantRolePermission) {
            if ($tenantRolePermission->getPermission()?->getCode() === $permission) {
                return true;
            }
        }

        return false;
    }
}
