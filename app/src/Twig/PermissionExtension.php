<?php

namespace App\Twig;

use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PermissionExtension extends AbstractExtension
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_access_module', $this->canAccessModule(...)),
            new TwigFunction('can_administer', $this->canAdminister(...)),
            new TwigFunction('has_permission', $this->hasPermission(...)),
            new TwigFunction('can_access_resource', $this->canAccessResource(...)),
        ];
    }

    public function canAccessModule(string $module): bool
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof User || $tenant === null) {
            return false;
        }

        return $this->permissionChecker->canAccessModule($user, $tenant, $module);
    }

    public function canAdminister(string $permission): bool
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof User || $tenant === null) {
            return false;
        }

        return $this->permissionChecker->canAdminister($user, $tenant, $permission);
    }

    public function hasPermission(string $permission): bool
    {
        $user = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$user instanceof User || $tenant === null) {
            return false;
        }

        return $this->permissionChecker->hasPermission($user, $tenant, $permission);
    }

    /**
     * `can_access_resource('pasta', pasta.id, 'delete')`: a MESMA decisão que o controller toma
     * com `denyResourceAccessUnlessGranted` (PermissionChecker::canAccessResource), para a tela
     * não oferecer o controle que o servidor recusaria. É só honestidade na interface — o
     * servidor continua conferindo. Cada chamada consulta o banco (vínculo do usuário e acesso
     * específico ao recurso): guarde o resultado num `set` em vez de chamar dentro de laço grande.
     */
    public function canAccessResource(string $resourceType, int $resourceId, string $action): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $this->permissionChecker->canAccessResource($user, $this->tenantContext->getCurrentTenant(), $resourceType, $resourceId, $action);
    }
}
