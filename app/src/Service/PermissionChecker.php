<?php

namespace App\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\NivelNoCadeado;
use App\Repository\ResourceAccessRepository;
use App\Repository\UserTenantRepository;

class PermissionChecker
{
    public function __construct(
        private readonly ResourceAccessRepository $resourceAccessRepository,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly NivelNoCadeado $nivelNoCadeado,
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

        if ($userTenant === null) {
            return false;
        }

        return $this->canAccessResourceComVinculo($user, $userTenant, $resourceType, $resourceId, $action);
    }

    /**
     * O MESMO {@see canAccessResource()}, para quem já tem o vínculo da pessoa com o escritório em mãos
     * (a consulta que o trouxe evita uma ida ao banco por pessoa, como na lista de quem acompanha a
     * pasta). O escritório é o do vínculo. Vínculo inativo ou de outra pessoa recusa, como o
     * `resolveUserTenant`; o super admin global passa, como lá.
     */
    public function canAccessResourceComVinculo(User $user, UserTenant $userTenant, string $resourceType, int $resourceId, string $action): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if (!$userTenant->isActive() || !self::mesmoUsuario($userTenant->getUser(), $user)) {
            return false;
        }

        if ($userTenant->getTenantRole()?->isSystem() === true) {
            return true;
        }

        if (!$this->cabeNoCadeadoDaPasta($user, $userTenant, $resourceType, $resourceId, $action)) {
            return false;
        }

        return $this->permiteORecurso($user, $userTenant, $resourceType, $resourceId, $action);
    }

    private static function mesmoUsuario(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }

    /**
     * O `canAccessResource` SEM o teto do cadeado da pasta: a permissão que a pessoa teria se a pasta
     * estivesse livre. Só para o pedido de acesso (spec pasta-cadeado K5): quem não veria a pasta nem sem
     * cadeado não tem o que pedir ao primário. Nunca use para liberar uma tela.
     */
    public function canAccessResourceIgnoringPastaLock(User $user, ?Tenant $tenant, string $resourceType, int $resourceId, string $action): bool
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

        return $this->permiteORecurso($user, $userTenant, $resourceType, $resourceId, $action);
    }

    private function permiteORecurso(User $user, UserTenant $userTenant, string $resourceType, int $resourceId, string $action): bool
    {
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

    /**
     * O teto do cadeado da pasta (spec pasta-cadeado §3), depois dos bypasses e ANTES do `ResourceAccess`
     * e do fallback por tipo: `view` exige pelo menos `Visualizar`, as demais ações exigem `Editar`. Assim
     * um `AccessRequest` aprovado nunca fura o cadeado. O processo cujas pastas são TODAS ocultas para a
     * pessoa também é recusado (K3). Outros tipos de recurso passam reto.
     */
    private function cabeNoCadeadoDaPasta(User $user, UserTenant $userTenant, string $resourceType, int $resourceId, string $action): bool
    {
        if ($resourceType === AccessRequest::RESOURCE_PROCESSO) {
            // K3: o processo cujas pastas são todas ocultas para a pessoa não abre para ela.
            return !$this->nivelNoCadeado->processoOcultoComVinculo($user, $userTenant, $resourceId);
        }
        if ($resourceType !== AccessRequest::RESOURCE_PASTA) {
            return true;
        }

        $nivel = $this->nivelNoCadeado->nivelComVinculo($user, $userTenant, $resourceId);

        return $action === AccessRequest::ACTION_VIEW ? $nivel->podeVer() : $nivel->podeEditar();
    }

    /**
     * Administrador do escritório: vínculo ativo com o perfil de sistema (o mesmo bypass das checagens
     * acima), ou o super admin global. Quem vê todas as pastas no relatório de pastas sem processo
     * (`PastasVisiveisNoRelatorio`); o cadeado é aplicado à parte, por `AcessoAPasta::idsOcultosPara`.
     */
    public function isAdministradorDoEscritorio(User $user, ?Tenant $tenant): bool
    {
        if ($this->isGlobalSuperAdmin($user)) {
            return true;
        }

        if ($tenant === null) {
            return false;
        }

        return $this->resolveUserTenant($user, $tenant)?->getTenantRole()?->isSystem() === true;
    }

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
