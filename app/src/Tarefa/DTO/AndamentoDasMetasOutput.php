<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * Números do cartão "Seu andamento" (desenho 04, dc L112-118 e JS `andTxt`/`andLeg`).
 *
 * Saem de TODAS as metas da pessoa (aba Todas, concluídas só dos últimos 30 dias, sem as pastas
 * ocultas pelo cadeado), nunca da aba aberta nem dos filtros da tela: o desenho conta `M`, a lista
 * inteira, e não `L`, a filtrada. Vêm de uma consulta agregada (`TarefaRepository::contarAndamento`).
 */
final class AndamentoDasMetasOutput
{
    public function __construct(
        public readonly int $concluidas = 0,
        public readonly int $total = 0,
        public readonly int $atrasadas = 0,
        public readonly int $emRevisao = 0,
    ) {
    }

    public function percentualConcluido(): int
    {
        return $this->total === 0 ? 0 : (int) round($this->concluidas / $this->total * 100);
    }
}
