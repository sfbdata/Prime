<?php

declare(strict_types=1);

namespace App\Tests\Factory\Auth;

use App\Entity\Auth\UserTenant;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/** @extends PersistentProxyObjectFactory<UserTenant> */
final class UserTenantFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return UserTenant::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'user'   => UserFactory::new(),
            'tenant' => TenantFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
