<?php

declare(strict_types=1);

namespace App\Agenda\Exception;

/** Falha ao falar com o Google Agenda (rede, resposta inesperada). A sincronia tenta de novo na próxima vez. */
class GoogleAgendaException extends \RuntimeException
{
}
