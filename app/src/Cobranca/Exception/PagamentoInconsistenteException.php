<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * A soma das alocações do pagamento não fecha com o valor recuperado da dívida
 * (`valorDivida + valorEncargos`). O rateio precisa fechar exatamente em centavos — evita perder
 * ou inventar dinheiro no saldo derivado (SPEC §11, invariável 20).
 */
final class PagamentoInconsistenteException extends \DomainException
{
    public function __construct(int $somaAlocacoes, int $valorRecuperadoDivida)
    {
        parent::__construct(sprintf(
            'Soma das alocações (%d centavos) diverge do valor recuperado da dívida (%d centavos).',
            $somaAlocacoes,
            $valorRecuperadoDivida,
        ));
    }
}
