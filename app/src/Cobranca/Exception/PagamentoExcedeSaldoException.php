<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * A parte-dívida do pagamento excede o saldo exigível derivado do caso (Ajuste 6, decisão D1):
 * a auto-alocação FIFO não pode recuperar mais do que ainda se deve (o saldo já abate alocações e
 * liquidações). Bloqueia em vez de gerar saldo negativo — evita poluir os painéis derivados.
 */
final class PagamentoExcedeSaldoException extends \DomainException
{
    public function __construct(
        public readonly int $valorDivida,
        public readonly int $saldoDisponivel,
    ) {
        parent::__construct(sprintf(
            'A parte da dívida do pagamento (%d centavos) excede o saldo exigível do caso (%d centavos).',
            $valorDivida,
            $saldoDisponivel,
        ));
    }
}
