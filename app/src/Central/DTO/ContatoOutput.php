<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** Um colega com quem dá para abrir conversa. */
final class ContatoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
    ) {
    }
}
