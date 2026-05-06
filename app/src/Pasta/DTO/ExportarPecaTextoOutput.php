<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

final class ExportarPecaTextoOutput
{
    public function __construct(
        public readonly string $conteudo,
        public readonly string $mimeType,
        public readonly string $nomeArquivo,
    ) {}
}
