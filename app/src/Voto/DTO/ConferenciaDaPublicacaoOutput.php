<?php

declare(strict_types=1);

namespace App\Voto\DTO;

final readonly class ConferenciaDaPublicacaoOutput
{
    public function __construct(
        public AssembleiaNoPainel $assembleia,
        public string $codigo,
        public ?string $pendencia,
        public ?string $somaDasFracoes = null,
        public bool $somaForaDoEsperado = false,
        public int $cargosDaEquipe = 0,
        public int $cargosSemPessoa = 0,
    ) {
    }
}
