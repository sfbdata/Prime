<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\ObjetoCobranca;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `carteira`
 * (com o mesmo tenant) explicitamente.
 *
 * @extends PersistentProxyObjectFactory<ObjetoCobranca>
 */
final class ObjetoCobrancaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return ObjetoCobranca::class;
    }

    protected function defaults(): array
    {
        return [
            'identificacao' => self::faker()->bothify('Unidade ##?'),
            'tenant' => TenantFactory::new(),
            'carteira' => CarteiraFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
