<?php

declare(strict_types=1);

namespace App\Master\Enum;

enum TipoDeRegistroDeBackup: string
{
    case Backup = 'backup';
    case Restauracao = 'restauracao';
}
