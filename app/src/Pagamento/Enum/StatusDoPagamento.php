<?php

declare(strict_types=1);

namespace App\Pagamento\Enum;

enum StatusDoPagamento: string
{
    case Aguardando = 'aguardando';
    case Paga = 'paga';
    case Expirada = 'expirada';
    case Cancelada = 'cancelada';
    /** Chegou dinheiro que não bate com a cobrança (a menos, ou em cobrança já cancelada/expirada): o Master decide. */
    case Divergente = 'divergente';
    case Devolvida = 'devolvida';

    /** Ainda pode receber um pagamento que libera o consumidor. */
    public function aceitaPagamento(): bool
    {
        return $this === self::Aguardando;
    }
}
