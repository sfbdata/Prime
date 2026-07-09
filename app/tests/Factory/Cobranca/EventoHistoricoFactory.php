<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant` e `caso`
 * (com o mesmo tenant) explicitamente.
 *
 * @extends PersistentProxyObjectFactory<EventoHistorico>
 */
final class EventoHistoricoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return EventoHistorico::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'caso' => CasoCobrancaFactory::new(),
            'tipo' => TipoEventoHistorico::CasoAberto,
            'descricao' => self::faker()->sentence(),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
