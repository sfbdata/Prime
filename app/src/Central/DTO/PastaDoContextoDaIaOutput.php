<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * O rótulo da barra "Pasta N" da conversa com o BlueJus IA (PR 4b): só o que a barra mostra. Nada de financeiro,
 * processo, partes além do cliente principal, nem contexto da IA.
 */
final readonly class PastaDoContextoDaIaOutput
{
    public function __construct(
        public int $pastaId,
        public string $nup,
        public ?string $cliente,
    ) {
    }
}
