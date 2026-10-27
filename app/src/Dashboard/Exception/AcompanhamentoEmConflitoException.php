<?php

declare(strict_types=1);

namespace App\Dashboard\Exception;

/** A pessoa já está em acompanhamento (criar) ou não está (renovar, encerrar). Vira 409. */
final class AcompanhamentoEmConflitoException extends \DomainException
{
}
