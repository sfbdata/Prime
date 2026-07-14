<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Resultado da derivação da auto-alocação FIFO (Ajuste 6): a divisão dívida/honorários do valor pago,
 * o saldo disponível do caso e a quebra por obrigação — insumo tanto da prévia ao vivo (endpoint GET,
 * NÃO lança) quanto da escrita (que lança se `excede`). Tudo em CENTAVOS inteiros.
 */
final class PreviaAlocacaoFifo
{
    /** @param LinhaAlocacaoFifo[] $linhas quebra FIFO por obrigação; vazia quando `excede` */
    public function __construct(
        public readonly int $valorPago,
        public readonly int $valorDivida,
        public readonly int $valorHonorarios,
        public readonly int $saldoDisponivel,
        public readonly bool $excede,
        public readonly int $excedeEm,
        public readonly array $linhas,
    ) {
    }
}
