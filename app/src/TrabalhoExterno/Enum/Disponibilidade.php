<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/** Estado da central de solicitações (menu ⋮ do Master, "CENTRAL DE SOLICITAÇÕES"). */
enum Disponibilidade: string
{
    case Disponivel = 'on';
    case ComRestricoes = 'rest';
    case Suspensa = 'off';
}
