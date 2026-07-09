<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\Obrigacao;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `caso`
 * (com o mesmo tenant) explicitamente. Valores em CENTAVOS.
 *
 * @extends PersistentProxyObjectFactory<Obrigacao>
 */
final class ObrigacaoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Obrigacao::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'caso' => CasoCobrancaFactory::new(),
            'descricao' => self::faker()->bothify('Competência ##/2026'),
            'valorOriginal' => self::faker()->numberBetween(1000, 500000),
            'vencimentoOriginal' => \DateTimeImmutable::createFromInterface(
                self::faker()->dateTimeBetween('-1 year', 'now'),
            ),
            'encargosReconhecidos' => 0,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
