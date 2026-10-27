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
    // Agenda 1A (docs/specs/agenda-1a.md §3): as seis legendas do desenho são seis tipos.
    case Urgente = 'urg';
    case Pessoal = 'pess';
    case Lembrete = 'lemb';

    /** Os quatro tipos que o formulário de 18/10 oferece (os outros três são da tela 1A). */
    public function ehDoFormularioAntigo(): bool
    {
        return match ($this) {
            self::Meus, self::Audiencia, self::Reuniao, self::Prazo => true,
            self::Urgente, self::Pessoal, self::Lembrete => false,
        };
    }

    /** Lembrete padrão do desenho, em minutos antes. */
    public function lembretePadrao(): int
    {
        return match ($this) {
            self::Audiencia => 60,
            self::Reuniao   => 10,
            self::Prazo, self::Meus, self::Urgente, self::Pessoal, self::Lembrete => 15,
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
            self::Urgente   => '#f56954',
            self::Pessoal   => '#605ca8',
            self::Lembrete  => '#00c0ef',
        };
    }
}
