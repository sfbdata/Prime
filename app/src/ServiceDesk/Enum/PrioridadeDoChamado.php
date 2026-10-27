<?php

declare(strict_types=1);

namespace App\ServiceDesk\Enum;

enum PrioridadeDoChamado: string
{
    case Baixa = 'baixa';
    case Media = 'media';
    case Alta = 'alta';
    case Urgente = 'urgente';

    public function rotulo(): string
    {
        return match ($this) {
            self::Baixa => 'Baixa',
            self::Media => 'Média',
            self::Alta => 'Alta',
            self::Urgente => 'Urgente',
        };
    }
}
