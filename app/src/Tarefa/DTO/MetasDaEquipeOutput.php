<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Tarefa\Tarefa;

/**
 * Uma página da lista "Metas da equipe" (/tarefas/equipe) — o destino dos números de metas
 * da tabela Desempenho do Dashboard.
 */
final class MetasDaEquipeOutput
{
    /**
     * @param list<Tarefa>          $metas       a página corrente
     * @param array<string, string> $filtros     filtros normalizados e preenchidos (vão nos links de paginação)
     */
    public function __construct(
        public readonly string $status,
        public readonly string $titulo,
        public readonly string $descricao,
        public readonly array $metas,
        public readonly int $total,
        public readonly int $pagina,
        public readonly int $totalPaginas,
        public readonly array $filtros,
    ) {
    }

    public function estaVazia(): bool
    {
        return $this->metas === [];
    }
}
