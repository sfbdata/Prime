<?php

declare(strict_types=1);

namespace App\Central\Enum;

/**
 * A prioridade escolhida na tela de despacho (desenho BC:6218-6238). "Urgente" também avisa no sino de cada membro;
 * "Para hoje" é só o prefixo da mensagem.
 */
enum PrioridadeDoDespacho: string
{
    case Normal = 'normal';
    case Urgente = 'urgente';
    case ParaHoje = 'para_hoje';

    /** O começo da mensagem, com os emojis do desenho. */
    public function prefixo(): string
    {
        return match ($this) {
            self::Normal => '',
            self::Urgente => '🔴 URGENTE · ',
            self::ParaHoje => '🟠 PARA HOJE · ',
        };
    }
}
