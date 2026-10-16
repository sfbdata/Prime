<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** O grupo como a tela o recebe depois de criar ou renomear. */
final class GrupoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
    ) {
    }
}
