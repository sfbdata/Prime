<?php

declare(strict_types=1);

namespace App\ServiceDesk\Enum;

enum StatusDoChamado: string
{
    case Aberto = 'aberto';
    case Andamento = 'andamento';
    case Resolvido = 'resolvido';
    case Fechado = 'fechado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Aberto => 'Aberto',
            self::Andamento => 'Em andamento',
            self::Resolvido => 'Resolvido',
            self::Fechado => 'Fechado',
        };
    }
}
