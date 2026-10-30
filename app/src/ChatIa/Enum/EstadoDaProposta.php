<?php

declare(strict_types=1);

namespace App\ChatIa\Enum;

/** Ciclo da proposta: `pendente` vira `confirmada`, `cancelada` ou `expirada`, e nunca mais muda. */
enum EstadoDaProposta: string
{
    case Pendente = 'pendente';
    case Confirmada = 'confirmada';
    case Cancelada = 'cancelada';
    case Expirada = 'expirada';
}
