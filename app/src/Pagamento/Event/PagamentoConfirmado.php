<?php

declare(strict_types=1);

namespace App\Pagamento\Event;

use App\Pagamento\Enum\OrigemDoPagamento;

/**
 * Despachado DEPOIS do commit, só quando o pagamento liberou o consumidor. Cada consumidor (Trabalho Externo,
 * Service Desk, Contribua) ouve o evento da sua `origem` e age na própria transação. Carrega ids, não entidades.
 */
final class PagamentoConfirmado
{
    public function __construct(
        public readonly int $pagamentoId,
        public readonly int $tenantId,
        public readonly OrigemDoPagamento $origem,
        public readonly int $origemId,
        public readonly string $referencia,
        public readonly int $valorPagoCentavos,
        public readonly ?string $endToEndId,
        public readonly int $diferencaCentavos,
    ) {
    }
}
