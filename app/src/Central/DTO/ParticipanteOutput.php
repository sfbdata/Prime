<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** Uma pessoa do grupo na tela de informações. `ativoNoEscritorio = false`: saiu do escritório (não lê nem é avisada). */
final class ParticipanteOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly bool $administrador,
        public readonly bool $ativoNoEscritorio,
        public readonly bool $eu,
    ) {
    }
}
