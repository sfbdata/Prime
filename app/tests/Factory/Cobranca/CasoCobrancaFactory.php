<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusCaso;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant`, `objeto` e
 * `pessoaCobradaAtual` (todos do MESMO tenant) explicitamente — os defaults geram tenants
 * independentes.
 *
 * @extends PersistentProxyObjectFactory<CasoCobranca>
 */
final class CasoCobrancaFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return CasoCobranca::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'objeto' => ObjetoCobrancaFactory::new(),
            'pessoaCobradaAtual' => PessoaFactory::new(),
            'status' => StatusCaso::Ativo,
            'formaHonorarios' => FormaHonorarios::SemPercentual,
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
