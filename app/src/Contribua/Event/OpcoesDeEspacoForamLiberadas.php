<?php

declare(strict_types=1);

namespace App\Contribua\Event;

use App\Contribua\Enum\OrigemDaLiberacao;

/**
 * As opções de espaço extra ficaram visíveis para o escritório: pelo Master (ao liberar um pedido, `pedidoId`
 * preenchido) ou sozinhas quando o uso passou de 97% (`Auto`, `pedidoId` nulo, uma vez por marca gravada).
 */
final readonly class OpcoesDeEspacoForamLiberadas
{
    public function __construct(
        public int $tenantId,
        public OrigemDaLiberacao $origem,
        public ?int $pedidoId,
    ) {
    }
}
