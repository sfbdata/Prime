<?php

declare(strict_types=1);

namespace App\Tests\Factory\Tenant;

use App\Entity\Tenant\Tenant;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/** @extends PersistentProxyObjectFactory<Tenant> */
final class TenantFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Tenant::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'name' => self::faker()->company(),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
