<?php

declare(strict_types=1);

namespace App\Contribua\Event;

/**
 * O escritório usou "Solicitar mais espaço" (desenho Contribua 14 e 15/11). Os e-mails de espaço (Comercial) ouvem
 * este evento: confirmação ao escritório e aviso ao Master. Despachado depois do flush do pedido.
 */
final readonly class EspacoExtraFoiSolicitado
{
    public function __construct(
        public int $pedidoId,
        public int $tenantId,
        public int $gbDesejado,
        public ?int $pedidoPorUserId,
    ) {
    }
}
