<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\Pessoa;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Pessoa>
 */
final class PessoaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Pessoa::class;
    }

    protected function defaults(): array
    {
        return [
            'nome' => self::faker()->name(),
            'tenant' => TenantFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
