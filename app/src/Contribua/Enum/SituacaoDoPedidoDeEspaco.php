<?php

declare(strict_types=1);

namespace App\Contribua\Enum;

/** Situação do pedido "Solicitar mais espaço" (desenho Contribua 14 e 15/11, bj-plano.js). */
enum SituacaoDoPedidoDeEspaco: string
{
    case Pendente = 'pendente';
    case Liberado = 'liberado';
    case Recusado = 'recusado';
}
