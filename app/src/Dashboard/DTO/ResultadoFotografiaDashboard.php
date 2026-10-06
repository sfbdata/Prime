<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/** O que o `FotografarDashboardUseCase` fotografou (ou fotografaria, no dry-run) num escritório. */
final class ResultadoFotografiaDashboard
{
    /**
     * @param array<int, array{metas_ativas: int, demandas_ativas: int, metas_vencidas: int, prazos_proximos: int}> $contagens userId => números
     */
    public function __construct(
        public readonly array $contagens,
        /** Linhas gravadas no banco; 0 no dry-run. */
        public readonly int   $gravadas,
    ) {}

    public function colaboradores(): int
    {
        return \count($this->contagens);
    }
}
