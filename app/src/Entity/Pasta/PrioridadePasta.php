<?php

declare(strict_types=1);

namespace App\Entity\Pasta;

enum PrioridadePasta: string
{
    case Normal     = 'normal';
    case Prioridade = 'prioridade';
    case Urgente    = 'urgente';
}
