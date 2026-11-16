<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/** Ordem das candidaturas na lista da cédula (desenho 18): a de registro (pelo número) ou sorteada a cada visualização. */
enum OrdemDasCandidaturas: string
{
    case Fixa = 'fixa';
    case Sorteada = 'sorteada';
}
