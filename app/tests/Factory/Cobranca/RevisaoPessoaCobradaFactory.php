<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\RevisaoPessoaCobrada;
use App\Cobranca\Enum\StatusRevisao;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `caso` (com o mesmo
 * tenant) explicitamente — os defaults geram tenants independentes.
 *
 * @extends PersistentProxyObjectFactory<RevisaoPessoaCobrada>
 */
final class RevisaoPessoaCobradaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return RevisaoPessoaCobrada::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'caso' => CasoCobrancaFactory::new(),
            'motivo' => self::faker()->sentence(4),
            'status' => StatusRevisao::Pendente,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
