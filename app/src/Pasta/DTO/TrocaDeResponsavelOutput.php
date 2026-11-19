<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/** O que a troca de responsável fez: se mudou e se o anterior seguiu colaborando ("Manter como colaborador", L8). */
final readonly class TrocaDeResponsavelOutput
{
    public function __construct(
        public bool $mudou,
        public bool $colaboradorMantido = false,
    ) {
    }
}
