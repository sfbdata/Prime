<?php

declare(strict_types=1);

namespace App\Comercial\Exception;

/** O pedido do Master na Base de contatos não vale (a mensagem vai à tela como está). O controller responde 422. */
final class ContatoRecusado extends \DomainException
{
}
