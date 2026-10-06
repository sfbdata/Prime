<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\Agente;

final readonly class SolicitarAnaliseDaPastaInput
{
    public function __construct(
        public int $pastaId,
        public Agente $agente,
    ) {
    }
}
