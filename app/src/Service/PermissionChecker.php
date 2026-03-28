<?php

namespace App\Service;

use App\Entity\Auth\User;
use App\Repository\ResourceAccessRepository;

/**
 * Camada central de autorização semântica do JusPrime.
 *
 * Hierarquia de verificação:
 *  1. ROLE_SUPER_ADMIN → acesso total (fora do escopo de tenant).
 *  2. TenantRole com permissões do catálogo → modelo novo.
 *  3. ResourceAccess por item → controle granular.
 *
 * Controllers e serviços devem consumir este checker em vez de verificar
 * roles diretamente.
 */
class PermissionChecker
{
    public function __construct(
        private readonly ResourceAccessRepository $resourceAccessRepository,
    ) {}

    /**
     * Verifica se o usuário pode acessar um módulo (modules.*).
     *
     * @param User   $user       Usuário autenticado.
     * @param string $module     Slug do módulo (ex.: "clientes", "processos").
     *
     * Permissão verificada: modules.<module>.view
     */
    public function canAccessModule(User $user, string $module): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $permission = sprintf('modules.%s.view', $module);

        return $this->hasPermission($user, $permission);
    }

    /**
     * Verifica se o usuário pode executar uma ação administrativa (admin.*).
     *
     * @param User   $user       Usuário autenticado.
     * @param string $permission Código completo (ex.: "admin.roles.manage").
     */
    public function canAdminister(User $user, string $permission): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $this->hasPermission($user, $permission);
    }

    /**
     * Verifica se o usuário pode executar uma ação sobre um tipo de recurso (resources.*).
     *
     * @param User   $user         Usuário autenticado.
     * @param string $resourceType Tipo do recurso: "pasta", "cliente", "processo".
     * @param string $action       Ação desejada: "view", "edit" ou "delete".
     *
     * Permissão verificada: resources.<resourceType>.<action>
     *
     * Nota: esta verificação é para permissão de TIPO de recurso (nível de perfil).
     * Use canAccessResource() para checar por item individual.
     */
    public function canActOnResource(User $user, string $resourceType, string $action): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $permission = sprintf('resources.%s.%s', $resourceType, $action);

        return $this->hasPermission($user, $permission);
    }

    /**
     * Verifica se o usuário pode executar uma ação sobre um item específico de domínio.
     *
     * Hierarquia de verificação por item:
     *  1. ROLE_SUPER_ADMIN → acesso total.
     *  2. ResourceAccess por item → verifica registro específico (user + resourceType + resourceId).
     *  3. Permissão de tipo resources.<type>.<action> no TenantRole → fallback de perfil.
     *
     * @param User   $user         Usuário autenticado.
     * @param string $resourceType Tipo do recurso: "cliente", "pasta" ou "processo".
     * @param int    $resourceId   ID do item de domínio.
     * @param string $action       Ação desejada: "view", "edit" ou "delete".
     */
    public function canAccessResource(User $user, string $resourceType, int $resourceId, string $action): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Verificar acesso específico por item
        $resourceAccess = $this->resourceAccessRepository->findForUserAndResource($user, $resourceType, $resourceId);
        if ($resourceAccess !== null && $resourceAccess->allows($action)) {
            return true;
        }

        // Fallback: permissão de tipo no perfil do tenant
        return $this->canActOnResourceByTypeOnly($user, $resourceType, $action);
    }

    /**
     * Verifica se o usuário possui uma permissão semântica específica pelo código exato.
     *
     * Útil para checagens diretas sem passar pelo método tipado.
     *
     * @param User   $user       Usuário autenticado.
     * @param string $permission Código completo da permissão (ex.: "admin.audit.view").
     */
    public function hasPermission(User $user, string $permission): bool
    {
        $tenantRole = $user->getTenantRole();

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

    // -------------------------------------------------------------------------
    // Helpers internos
    // -------------------------------------------------------------------------

    private function isSuperAdmin(User $user): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    private function canActOnResourceByTypeOnly(User $user, string $resourceType, string $action): bool
    {
        $permission = sprintf('resources.%s.%s', $resourceType, $action);
        return $this->hasPermission($user, $permission);
    }
}
