<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/** Quem enxerga a aba Trabalho Externo (menu ⋮ do Master, "VISIBILIDADE DA ABA"). */
enum Visibilidade: string
{
    case Todos = 'todos';
    case Interno = 'interno';
    case Oculta = 'oculta';
}
