<?php

declare(strict_types=1);

namespace App\Shared\Texto;

/** O texto lido de um arquivo e se ele foi cortado no limite de caracteres (sem aviso dentro do texto). */
final class TextoExtraido
{
    public function __construct(
        public readonly string $texto,
        public readonly bool $cortado,
    ) {
    }
}
