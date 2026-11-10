<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Diagramacao;

/**
 * O bloco `a` posicionado (op `ass`): topo em `y`, linha centrada em `centro` com meia largura `meiaLargura`; `mini` nas
 * duas colunas (rubrica 34, nome 13,5 e data 11).
 */
final readonly class LugarDeAssinatura implements ElementoDaPagina
{
    public function __construct(
        public int $indice,
        public string $rotulo,
        public float $y,
        public float $centro,
        public float $meiaLargura,
        public bool $mini,
    ) {
    }
}
