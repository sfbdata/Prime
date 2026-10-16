<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** As preferências da conversa SÓ de quem pediu. */
final class PreferenciasOutput
{
    public function __construct(
        public readonly bool $fixada,
        public readonly bool $silenciada,
        public readonly bool $arquivada,
    ) {
    }
}
