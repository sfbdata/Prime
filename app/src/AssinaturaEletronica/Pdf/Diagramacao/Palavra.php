<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Diagramacao;

/** Uma palavra do texto e se ela está entre {b} e {/b}. */
final readonly class Palavra
{
    public function __construct(
        public string $texto,
        public bool $negrito = false,
    ) {
    }
}
