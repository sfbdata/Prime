<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Enum\TipoVinculo;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant`, `pessoa` e `objeto`
 * (com o mesmo tenant) explicitamente.
 *
 * @extends PersistentProxyObjectFactory<VinculoPessoaObjeto>
 */
final class VinculoPessoaObjetoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return VinculoPessoaObjeto::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'pessoa' => PessoaFactory::new(),
            'objeto' => ObjetoCobrancaFactory::new(),
            'tipoVinculo' => TipoVinculo::Proprietario,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
