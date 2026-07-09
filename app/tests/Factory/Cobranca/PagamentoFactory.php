<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\Pagamento;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `caso` (com o mesmo
 * tenant) explicitamente — os defaults geram tenants independentes. Valores em CENTAVOS.
 *
 * @extends PersistentProxyObjectFactory<Pagamento>
 */
final class PagamentoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Pagamento::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'caso' => CasoCobrancaFactory::new(),
            'data' => \DateTimeImmutable::createFromInterface(
                self::faker()->dateTimeBetween('-6 months', 'now'),
            ),
            'valorDivida' => self::faker()->numberBetween(1000, 500000),
            'valorEncargos' => 0,
            'valorHonorarios' => 0,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
