<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Níveis dos alertas do Modo avançado — `NIVEIS` de `bluejus-avancado.js` (L6-12), na ordem
 * em que o desenho os lista (crítico primeiro, oportunidade por último).
 */
enum NivelDeAlerta: string
{
    case Critico      = 'critico';
    case Atencao      = 'atencao';
    case Monitorar    = 'monitorar';
    case Normal       = 'normal';
    case Oportunidade = 'oportunidade';

    public function ordem(): int
    {
        return match ($this) {
            self::Critico      => 0,
            self::Atencao      => 1,
            self::Monitorar    => 2,
            self::Normal       => 3,
            self::Oportunidade => 4,
        };
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::Critico      => 'Crítico',
            self::Atencao      => 'Atenção',
            self::Monitorar    => 'Monitorar',
            self::Normal       => 'Normal',
            self::Oportunidade => 'Oportunidade',
        };
    }
}
