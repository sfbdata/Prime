<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** O item encaminhado como o servidor o releu: título e resumo em texto puro, sem token de menção ativo. */
final readonly class ItemEncaminhado
{
    public function __construct(
        public string $titulo,
        public string $resumo,
    ) {
    }
}
