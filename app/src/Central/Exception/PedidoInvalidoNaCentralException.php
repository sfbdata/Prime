<?php

declare(strict_types=1);

namespace App\Central\Exception;

/**
 * O pedido é de quem pode, mas não dá para atender: mensagem vazia ou longa demais, destinatário que não é do
 * escritório, conversa consigo mesmo, colega que saiu. O controller responde 422 com a mensagem.
 */
final class PedidoInvalidoNaCentralException extends \DomainException
{
}
