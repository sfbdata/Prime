<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

final class LinhaAdvogadoDashboardOutput
{
    public function __construct(
        public readonly int     $userId,
        public readonly string  $nomeAdvogado,
        public readonly ?string $cargoNome,
        public readonly ?string $fotoUrl,
        // Tarefa
        public readonly int    $totalMetas,
        public readonly int    $metasAtivas,
        public readonly int    $metasVencidas,
        public readonly int    $prazosProximos,
        // Pasta
        public readonly int    $totalDemandas,
        public readonly int    $demandasAtivas,
        /** Pastas abertas POR este colaborador (criadoPor), não as que ele responde. */
        public readonly int    $pastasCriadas,
        // TENDÊNCIA — mesmas três métricas no período anterior de mesma duração; null quando
        // não há período completo (sem período não há com o que comparar).
        public readonly ?int   $totalMetasAnterior = null,
        public readonly ?int   $totalDemandasAnterior = null,
        public readonly ?int   $pastasCriadasAnterior = null,
    ) {}
}
