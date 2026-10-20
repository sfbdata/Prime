<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/** O id não é de um e-mail deste cliente (ou de outro escritório): 404, sem revelar que existe. */
final class EmailDoClienteNaoEncontradoException extends \DomainException
{
}
