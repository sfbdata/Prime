<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;

/**
 * Distribuição da fila ativa entre os colaboradores com meta no período — porte de
 * `analisarEquipe()` de `bluejus-intelligence.js` (L118-128).
 *
 * `sobrecarga` é quem tem mais metas ativas quando passa da média além do limiar;
 * `folga` é quem tem menos (só quando há sobrecarga e é outra pessoa); `redistribuir`
 * é a sugestão de quantas metas mover de um para o outro. Tudo nulo/zero quando a
 * carga está perto da média.
 */
final readonly class EquipeDoRitmo
{
    public function __construct(
        /** Média de metas ativas entre quem tem meta no período (`totalMetas > 0`). */
        public float $media,
        public ?LinhaAdvogadoDashboardOutput $sobrecarga,
        /** `top / média − 1` (só significa algo quando há sobrecarga). */
        public float $razao,
        public ?LinhaAdvogadoDashboardOutput $folga,
        public int $redistribuir,
    ) {}
}
