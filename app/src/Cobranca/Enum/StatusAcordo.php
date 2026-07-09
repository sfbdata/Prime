<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Estado do Acordo (SPEC §12). Um acordo VIGENTE (`ativo` ou `cumprido`) mantém a substituição das
 * obrigações originais (fora do saldo) e as parcelas (dentro); um acordo `rompido`/`cancelado`
 * desfaz o efeito no saldo derivado (originais voltam, parcelas saem) — invariável 20, sem reversão
 * imperativa. Rompimento e cancelamento são sempre manuais e registram motivo.
 */
enum StatusAcordo: string
{
    case Ativo = 'ativo';
    case Cumprido = 'cumprido';
    case Rompido = 'rompido';
    case Cancelado = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Ativo => 'Ativo',
            self::Cumprido => 'Cumprido',
            self::Rompido => 'Rompido',
            self::Cancelado => 'Cancelado',
        };
    }

    /** Acordo vigente: a substituição das obrigações vale e as parcelas compõem o saldo. */
    public function ehVigente(): bool
    {
        return match ($this) {
            self::Ativo, self::Cumprido => true,
            self::Rompido, self::Cancelado => false,
        };
    }
}
