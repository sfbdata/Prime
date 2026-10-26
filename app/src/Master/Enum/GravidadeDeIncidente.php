<?php

declare(strict_types=1);

namespace App\Master\Enum;

enum GravidadeDeIncidente: string
{
    case Critica = 'crit';
    case Alta = 'alta';
    case Media = 'media';
    case Baixa = 'baixa';
}
