<?php

declare(strict_types=1);

namespace App\Agenda\Exception;

/** O Google não aceita mais o syncToken (410 Gone): a sincronia recomeça do zero. */
final class SyncTokenVencidoException extends GoogleAgendaException
{
}
