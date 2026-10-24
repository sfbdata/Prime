<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/** A imagem enviada para a foto do cliente não é JPEG, PNG ou WebP de verdade, ou passa de 3 MB. O controller responde 422. */
final class FotoDoClienteInvalidaException extends \DomainException
{
}
