<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `cliente`
 * (com o mesmo tenant) explicitamente — os defaults geram tenants independentes.
 *
 * @extends PersistentProxyObjectFactory<Carteira>
 */
final class CarteiraFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Carteira::class;
    }

    protected function defaults(): array
    {
        return [
            'nome' => self::faker()->company(),
            'tenant' => TenantFactory::new(),
            'cliente' => ClientePFFactory::new(),
            'modo' => ModoCarteira::Unico,
            'formaHonorarios' => FormaHonorarios::SemPercentual,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
