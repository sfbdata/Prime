<?php

declare(strict_types=1);

namespace App\Contribua\Enum;

/**
 * Quem liberou as opções de espaço extra do escritório (desenho Contribua 14 e 15/11). `Master` fica gravada até
 * nova decisão; `Auto` só vale enquanto o uso estiver em 97% ou mais (decisão D3 de 16/11).
 */
enum OrigemDaLiberacao: string
{
    case Master = 'master';
    case Auto = 'auto';
}
