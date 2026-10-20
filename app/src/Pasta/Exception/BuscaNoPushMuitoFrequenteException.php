<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/** "Buscar agora" de novo antes de um minuto na mesma pasta (o controller responde 429). */
final class BuscaNoPushMuitoFrequenteException extends \RuntimeException
{
}
