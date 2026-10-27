<?php

declare(strict_types=1);

namespace App\Recompensa\Exception;

/** Pedido de mudança nas recompensas que a regra não aceita (a mensagem vai ao Master no 422). */
final class RecompensaRecusada extends \DomainException
{
}
