<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que o parcelamento gravou: os ids dos lançamentos, na ordem (entrada
 * primeiro, depois as parcelas), e a soma gravada em decimal.
 */
final class ParcelamentoRegistradoOutput
{
    /**
     * @param list<int> $ids
     */
    public function __construct(
        public readonly array $ids,
        public readonly int $quantidade,
        /** Soma dos lançamentos gravados, no formato de `decimal(15,2)` ("17163.82"). */
        public readonly string $totalLancado,
    ) {
    }
}
