<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Enum\Automacao;

/** O estado de uma automação, como o Master vê e como as varreduras usam. */
final class EstadoDaAutomacao
{
    public function __construct(
        public readonly Automacao $automacao,
        public readonly bool $ligada,
        public readonly ?string $atraso,
        /** O corte: as varreduras só olham fatos depois daqui. Null = nunca ligada. */
        public readonly ?\DateTimeImmutable $ligadaEm,
    ) {
    }
}
