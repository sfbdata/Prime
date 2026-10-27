<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

use App\ServiceDesk\Enum\StatusDoChamado;

final class ChamadosDoEscritorioOutput
{
    /**
     * @param list<ChamadoDaListaOutput> $chamados filtrados, mais recentes primeiro
     * @param array<string, int>         $contagem por status (chave = valor do enum); os KPIs
     */
    public function __construct(
        public readonly array $chamados,
        public readonly array $contagem,
        public readonly int $totalDoEscritorio,
    ) {
    }

    public function quantosEm(StatusDoChamado $status): int
    {
        return $this->contagem[$status->value] ?? 0;
    }
}
