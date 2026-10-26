<?php

declare(strict_types=1);

namespace App\ServiceDesk\Enum;

enum FormaDeRecarga: string
{
    case Pix = 'pix';
    case Cartao = 'cartao';
}
