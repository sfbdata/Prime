<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Diagramacao;

use App\AssinaturaEletronica\Pdf\Alinhamento;

/**
 * Uma linha de texto (op `l` da página inteira e `t2` das duas colunas): palavras na caixa de `x0` a `x0 + largura`,
 * com a linha de base em `base`. Justificada, o espaço entre as palavras estica até a margem; senão é `espaco`.
 */
final readonly class LinhaDeTexto implements ElementoDaPagina
{
    /** @param list<Palavra> $palavras */
    public function __construct(
        public array $palavras,
        public float $x0,
        public float $largura,
        public float $base,
        public float $tamanho,
        public float $espaco,
        public Alinhamento $alinhamento,
        public bool $justificar,
        public bool $tudoNegrito,
    ) {
    }
}
