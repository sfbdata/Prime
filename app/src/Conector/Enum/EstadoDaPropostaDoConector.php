<?php

declare(strict_types=1);

namespace App\Conector\Enum;

/**
 * Estado da proposta que espera a confirmação da pessoa no chat (`docs/specs/mcp-remoto-oauth.md`, 6.7). Transição
 * única: `pendente` para `confirmada`, `cancelada` ou `expirada`.
 */
enum EstadoDaPropostaDoConector: string
{
    case Pendente = 'pendente';
    case Confirmada = 'confirmada';
    case Cancelada = 'cancelada';
    case Expirada = 'expirada';
}
