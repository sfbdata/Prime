<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Enum\StatusAcordo;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `caso` (com o mesmo
 * tenant) explicitamente — os defaults geram tenants independentes.
 *
 * @extends PersistentProxyObjectFactory<Acordo>
 */
final class AcordoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Acordo::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'caso' => CasoCobrancaFactory::new(),
            'status' => StatusAcordo::Ativo,
            'dataAcordo' => \DateTimeImmutable::createFromInterface(
                self::faker()->dateTimeBetween('-3 months', 'now'),
            ),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
