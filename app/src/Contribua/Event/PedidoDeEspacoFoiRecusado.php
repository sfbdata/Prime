<?php

declare(strict_types=1);

namespace App\Contribua\Event;

/** O Master recusou um pedido de mais espaço (desenho Contribua 14 e 15/11). O escritório é avisado por e-mail. */
final readonly class PedidoDeEspacoFoiRecusado
{
    public function __construct(
        public int $pedidoId,
        public int $tenantId,
    ) {
    }
}
