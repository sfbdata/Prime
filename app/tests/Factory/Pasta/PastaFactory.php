<?php

declare(strict_types=1);

namespace App\Tests\Factory\Pasta;

use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/** @extends PersistentProxyObjectFactory<Pasta> */
final class PastaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Pasta::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'nup'    => self::faker()->unique()->numerify('PROC-########'),
            'tenant' => TenantFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
