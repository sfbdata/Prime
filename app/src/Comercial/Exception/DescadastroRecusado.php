<?php

declare(strict_types=1);

namespace App\Comercial\Exception;

/** O pedido do Master na lista de descadastrados não vale (a mensagem vai à tela como está). O controller responde 422. */
final class DescadastroRecusado extends \DomainException
{
}
