<?php

declare(strict_types=1);

namespace App\Djen\Enum;

/** Natureza da publicação do Diário da OAB: a ético-disciplinar é sigilosa (art. 72 da Lei nº 8.906/1994). */
enum TipoDeAtoDoDiario: string
{
    case EticoDisciplinar = 'etico';
    case Comunicado = 'comunicado';

    public function rotulo(): string
    {
        return match ($this) {
            self::EticoDisciplinar => 'Processo ético-disciplinar',
            self::Comunicado => 'Comunicado institucional',
        };
    }

    public function sigiloso(): bool
    {
        return $this === self::EticoDisciplinar;
    }
}
