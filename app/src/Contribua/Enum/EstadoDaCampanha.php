<?php

declare(strict_types=1);

namespace App\Contribua\Enum;

/** Em que pé a campanha "BlueJus Black" está hoje (calculado, nunca gravado). */
enum EstadoDaCampanha: string
{
    case Desligada = 'desligada';
    case Agendada = 'agendada';
    case Encerrada = 'encerrada';
    case Esgotada = 'esgotada';
    case Ativa = 'ativa';

    public function rotulo(): string
    {
        return match ($this) {
            self::Ativa => 'No ar',
            self::Esgotada => 'Esgotada',
            self::Agendada => 'Agendada',
            self::Encerrada => 'Encerrada',
            self::Desligada => 'Desligada',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Ativa => '#1e7b45',
            self::Esgotada => '#9a5b00',
            self::Agendada => '#0a6fa0',
            self::Encerrada, self::Desligada => '#5f7684',
        };
    }

    /** Aberta ao público (ativa ou esgotada): é quando a aba, a faixa e o FAQ aparecem para todos. */
    public function aoPublico(): bool
    {
        return $this === self::Ativa || $this === self::Esgotada;
    }
}
