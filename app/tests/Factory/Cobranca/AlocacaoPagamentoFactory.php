<?php

declare(strict_types=1);

namespace App\Tests\Factory\Cobranca;

use App\Cobranca\Entity\AlocacaoPagamento;
use App\Tests\Factory\Tenant\TenantFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * Cenários que dependem de consistência de tenant devem passar `tenant`, `pagamento` e `obrigacao`
 * (todos do MESMO tenant, com a obrigação do MESMO caso do pagamento — invariável 12)
 * explicitamente. Valor em CENTAVOS.
 *
 * @extends PersistentProxyObjectFactory<AlocacaoPagamento>
 */
final class AlocacaoPagamentoFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return AlocacaoPagamento::class;
    }

    protected function defaults(): array
    {
        return [
            'tenant' => TenantFactory::new(),
            'pagamento' => PagamentoFactory::new(),
            'obrigacao' => ObrigacaoFactory::new(),
            'valor' => self::faker()->numberBetween(1000, 500000),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}
