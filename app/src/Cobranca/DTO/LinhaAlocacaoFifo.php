<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Uma linha derivada da auto-alocação FIFO (Ajuste 6): quanto da parte-dívida do pagamento cabe em
 * UMA obrigação exigível, na ordem por vencimento. Carrega descrição e vencimento para a prévia ao
 * vivo; a escrita usa só `obrigacaoId` + `valor`. Valor em CENTAVOS inteiros.
 */
final class LinhaAlocacaoFifo
{
    public function __construct(
        public readonly int $obrigacaoId,
        public readonly string $descricao,
        public readonly \DateTimeImmutable $vencimento,
        public readonly int $valor,
    ) {
    }
}
