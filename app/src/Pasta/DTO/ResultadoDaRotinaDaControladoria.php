<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/** Contagem da rotina de segunda-feira da Controladoria em um escritório. */
final readonly class ResultadoDaRotinaDaControladoria
{
    public function __construct(
        public int $pastas,
        public int $pastasComAlertaNovo,
        public int $alertasNovos,
    ) {
    }
}
