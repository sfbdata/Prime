<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/** A chave do "Avisar quando chegar" não pode mudar agora; a mensagem é a dica do motivo (422). */
final class ChaveDoAvisoTravadaException extends \DomainException
{
}
