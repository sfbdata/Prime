<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/** O arquivo do "Exportar destinatários" (spec `comercial-marcas.md`, regra 6): o nome, o conteúdo e quantas linhas. */
final class DestinatariosEmCsv
{
    public function __construct(
        public readonly string $nomeDoArquivo,
        public readonly string $conteudo,
        public readonly int $linhas,
    ) {
    }
}
