<?php

declare(strict_types=1);

namespace App\Voto\DTO;

final readonly class ConferenciaDaPublicacaoOutput
{
    public function __construct(
        public AssembleiaNoPainel $assembleia,
        public string $codigo,
        public ?string $pendencia,
    ) {
    }
}
