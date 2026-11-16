<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** A cópia da assembleia: o id do novo rascunho e quantas unidades foram copiadas (zero: a lista precisa ser importada). */
final readonly class ResultadoDaDuplicacao
{
    public function __construct(
        public int $assembleiaId,
        public int $unidadesCopiadas,
    ) {
    }
}
