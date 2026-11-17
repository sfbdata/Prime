<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/** De onde veio a ocorrência: da coluna 8 da lista importada ou de quem a registrou na tela. */
enum OrigemDaOcorrencia: string
{
    case Importacao = 'importacao';
    case Manual = 'manual';
}
