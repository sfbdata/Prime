<?php

declare(strict_types=1);

namespace App\Agenda\Enum;

/**
 * Tipo do compromisso no modal do desenho 09 (Compromisso, Audiência, Reunião, Prazo). Define o
 * calendário em que aparece, a cor e o lembrete padrão.
 */
enum TipoDeCompromisso: string
{
    case Meus = 'meus';
    case Audiencia = 'aud';
    case Reuniao = 'reun';
    case Prazo = 'prazo';

    /** Lembrete padrão do desenho, em minutos antes. */
    public function lembretePadrao(): int
    {
        return match ($this) {
            self::Audiencia => 60,
            self::Reuniao   => 10,
            self::Prazo, self::Meus => 15,
        };
    }

    /** Cor do calendário no desenho; a agenda antiga também mostra o evento com ela. */
    public function cor(): string
    {
        return match ($this) {
            self::Meus      => '#0a6fa0',
            self::Audiencia => '#5b3f9e',
            self::Reuniao   => '#1e7b45',
            self::Prazo     => '#c0392b',
        };
    }
}
