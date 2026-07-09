<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Enum\TipoLiquidacao;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `caso` (com o mesmo
 * tenant) explicitamente. Valores em CENTAVOS — `valorReconhecido` (reduz o saldo) pode diferir de
 * `valorAtribuidoBem` (SPEC §11).
 *
 * @extends PersistentProxyObjectFactory<Liquidacao>
 */
final class LiquidacaoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Liquidacao::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'caso' => CasoCobrancaFactory::new(),
            'tipo' => self::faker()->randomElement(TipoLiquidacao::cases()),
            'descricaoBem' => self::faker()->words(3, true),
            'valorAtribuidoBem' => self::faker()->numberBetween(1000, 500000),
            'valorReconhecido' => self::faker()->numberBetween(1000, 500000),
            'data' => \DateTimeImmutable::createFromInterface(
                self::faker()->dateTimeBetween('-6 months', 'now'),
            ),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
