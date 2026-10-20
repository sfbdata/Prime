<?php

declare(strict_types=1);

namespace App\Contribua\Enum;

/** As faixas de uso do desenho 05 (Atenção, Alerta, Crítico, Bloqueio), mais "livre" abaixo da primeira. */
enum FaixaDoEspaco: string
{
    case Livre = 'livre';
    case Atencao = 'atencao';
    case Alerta = 'alerta';
    case Critico = 'critico';
    case Bloqueio = 'bloqueio';

    /** Posição da faixa, para saber se o uso subiu. */
    public function ordem(): int
    {
        return match ($this) {
            self::Livre => 0,
            self::Atencao => 1,
            self::Alerta => 2,
            self::Critico => 3,
            self::Bloqueio => 4,
        };
    }
}
