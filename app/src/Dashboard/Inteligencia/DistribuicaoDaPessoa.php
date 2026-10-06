<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Uma barra da "Distribuição da equipe" (`bluejus-avancado.js` L215-216): metas ativas e
 * vencidas da pessoa, em percentual da maior fila da tabela (para a largura da barra).
 */
final readonly class DistribuicaoDaPessoa
{
    public function __construct(
        public string $nome,
        public int $ativas,
        public int $vencidas,
        public int $pctAtivas,
        public int $pctVencidas,
    ) {}
}
