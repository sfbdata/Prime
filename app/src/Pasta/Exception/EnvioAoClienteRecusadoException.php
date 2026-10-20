<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/** O envio da análise ao cliente recusado por regra (422); a mensagem é a do desenho e vai ao modal. */
final class EnvioAoClienteRecusadoException extends \DomainException
{
}
