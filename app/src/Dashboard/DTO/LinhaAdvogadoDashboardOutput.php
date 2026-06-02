<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

final class LinhaAdvogadoDashboardOutput
{
    public function __construct(
        public readonly int     $userId,
        public readonly string  $nomeAdvogado,
        public readonly ?string $cargoNome,
        // Tarefa
        public readonly int    $totalMetas,
        public readonly int    $metasAtivas,
        public readonly int    $metasVencidas,
        public readonly int    $prazosProximos,
        // Pasta
        public readonly int    $totalDemandas,
        public readonly int    $demandasAtivas,
    ) {}
}
