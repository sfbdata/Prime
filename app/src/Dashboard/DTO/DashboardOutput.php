<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

final class DashboardOutput
{
    /**
     * @param LinhaAdvogadoDashboardOutput[] $porAdvogado
     */
    public function __construct(
        // CARDS
        public readonly int   $totalMetasAtivas,
        public readonly int   $demandasUrgentes,
        public readonly int   $metaGlobalPercent,
        // TABELA
        public readonly array $porAdvogado,
        // CARDS (acrescentados no fim, com default, para não quebrar quem constrói por posição)
        /** Soma de `pastasCriadas` das linhas: respeita período, responsável e cargo. */
        public readonly int   $totalPastasCriadas = 0,
        /** Numerador e denominador do `metaGlobalPercent`, para a legenda "X de Y metas concluídas". */
        public readonly int   $metasConcluidas = 0,
        public readonly int   $metasTotal = 0,
    ) {}
}
