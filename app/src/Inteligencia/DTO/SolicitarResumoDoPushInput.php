<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

final readonly class SolicitarResumoDoPushInput
{
    public function __construct(
        public int $pastaId,
    ) {
    }
}
