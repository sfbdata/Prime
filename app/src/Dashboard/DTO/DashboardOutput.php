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
    ) {}
}
