<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/** Pedido sobre os e-mails do cliente recusado por regra; a mensagem é a do desenho e vai à tela (422). */
final class EmailDoClienteRecusadoException extends \DomainException
{
}
