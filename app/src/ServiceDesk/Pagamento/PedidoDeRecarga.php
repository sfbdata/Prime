<?php

declare(strict_types=1);

namespace App\ServiceDesk\Pagamento;

use App\ServiceDesk\Enum\FormaDeRecarga;

/** O que o provedor precisa para cobrar uma recarga. `referencia` é o id da cobrança (txid no PIX). */
final class PedidoDeRecarga
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $referencia,
        public readonly int $valorCentavos,
        public readonly FormaDeRecarga $forma,
        public readonly string $descricao,
    ) {
    }
}
