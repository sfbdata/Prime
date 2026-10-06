<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * A pasta pedida não é do escritório da sessão. O controller responde 404 — nunca 403 — para
 * não revelar sequer que a pasta existe em outro escritório.
 */
final class PastaDeOutroEscritorioException extends \DomainException
{
}
