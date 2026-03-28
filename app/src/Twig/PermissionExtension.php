<?php

namespace App\Twig;

use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig Extension que expõe verificações do PermissionChecker nos templates.
 *
 * Funções disponíveis:
 *   - can_access_module(module)        → bool
 *   - can_administer(permission)       → bool
 *   - has_permission(permission)       → bool
 */
class PermissionExtension extends AbstractExtension
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly Security $security
    ) {
    }

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

        if (!$user instanceof User) {
            return false;
        }

        return $this->permissionChecker->canAccessModule($user, $module);
    }

    public function canAdminister(string $permission): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $this->permissionChecker->canAdminister($user, $permission);
    }

    public function hasPermission(string $permission): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $this->permissionChecker->hasPermission($user, $permission);
    }
}
