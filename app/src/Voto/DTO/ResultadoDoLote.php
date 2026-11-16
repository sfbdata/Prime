<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** O resultado de uma ação em lote sobre as assembleias marcadas: quantas foram feitas e quantas não se encaixam. */
final readonly class ResultadoDoLote
{
    public function __construct(
        public int $feitas,
        public int $mantidas,
    ) {
    }
}
