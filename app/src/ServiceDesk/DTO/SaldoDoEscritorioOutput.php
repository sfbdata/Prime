<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

final class SaldoDoEscritorioOutput
{
    /** @param list<MovimentoDoExtratoOutput> $extrato mais recentes primeiro */
    public function __construct(
        public readonly int $saldoCentavos,
        public readonly int $pendenteCentavos,
        public readonly bool $podeAbrirChamado,
        public readonly array $extrato,
    ) {
    }
}
