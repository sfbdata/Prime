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
}
