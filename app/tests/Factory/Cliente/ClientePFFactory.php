<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cliente;

use App\Cliente\Entity\ClientePF;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Factory de Cliente pessoa física — infraestrutura de teste compartilhada.
 * Necessária porque a Carteira de Cobrança exige um Cliente credor (invariável 4).
 *
 * @extends PersistentProxyObjectFactory<ClientePF>
 */
final class ClientePFFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return ClientePF::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'cep' => self::faker()->numerify('#####-###'),
            'endereco' => self::faker()->streetAddress(),
            'cidade' => self::faker()->city(),
            'estado' => 'PR',
            'tenant' => TenantFactory::new(),
            'cpf' => self::faker()->unique()->numerify('###.###.###-##'),
            'rg' => self::faker()->numerify('##.###.###-#'),
            'rgOrgaoExpedidor' => 'SSP',
            'nomeCompleto' => self::faker()->name(),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
