<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\ProximaAcao;
use App\Cobranca\Enum\StatusProximaAcao;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `caso` (com o mesmo
 * tenant) explicitamente — os defaults geram tenants independentes.
 *
 * @extends PersistentProxyObjectFactory<ProximaAcao>
 */
final class ProximaAcaoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return ProximaAcao::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'caso' => CasoCobrancaFactory::new(),
            'descricao' => self::faker()->sentence(4),
            'status' => StatusProximaAcao::Pendente,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
