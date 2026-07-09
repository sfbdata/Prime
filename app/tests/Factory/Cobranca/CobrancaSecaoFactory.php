<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\CobrancaSecao;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `caso` (com o mesmo
 * tenant) explicitamente — os defaults geram tenants independentes.
 *
 * @extends PersistentProxyObjectFactory<CobrancaSecao>
 */
final class CobrancaSecaoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return CobrancaSecao::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'caso' => CasoCobrancaFactory::new(),
            'nome' => self::faker()->words(2, true),
            'ordem' => 0,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
