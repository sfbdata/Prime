<?php

declare(strict_types=1);

namespace App\Djen\DTO;

final class BuscaDeProcessosDaOabOutput
{
    /** @param list<string> $oabs rótulos "NUMERO/UF" das OABs consultadas */
    public function __construct(
        public readonly int $processos,
        public readonly array $oabs,
        public readonly ResultadoSincronizacao $resultado,
    ) {
    }
}
