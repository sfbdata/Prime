<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/** O processo pedido não está vinculado a esta pasta (o controller responde 404, nunca 403). */
final class ProcessoForaDaPastaException extends \DomainException
{
}
