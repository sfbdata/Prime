<?php

declare(strict_types=1);

namespace App\ServiceDesk\Enum;

enum TipoDeMovimento: string
{
    case Credito = 'credito';
    case Debito = 'debito';
    case Reembolso = 'reembolso';

    /** Soma (+1) ou subtrai (-1) do saldo quando confirmado. */
    public function sinal(): int
    {
        return self::Debito === $this ? -1 : 1;
    }
}
