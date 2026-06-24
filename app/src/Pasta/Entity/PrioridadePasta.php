<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

enum PrioridadePasta: string
{
    case Normal     = 'normal';
    case Prioridade = 'prioridade';
    case Urgente    = 'urgente';

    public function label(): string
    {
        return match ($this) {
            self::Normal     => 'Normal',
            self::Prioridade => 'Prioridade',
            self::Urgente    => 'Urgente',
        };
    }
}
