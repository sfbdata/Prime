<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/** Resultado paginado de `buscar_pastas`. */
final class BuscaDePastasDoAgenteOutput
{
    /** @param list<PastaDoAgenteOutput> $pastas */
    public function __construct(
        public readonly array $pastas,
        public readonly int $total,
        public readonly int $pagina,
        public readonly int $porPagina,
    ) {
    }
}
