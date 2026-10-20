<?php

declare(strict_types=1);

namespace App\Agenda\Exception;

/** A agenda do escritório não existe mais na conta Google (a pessoa a apagou lá): a sincronia cria outra. */
final class AgendaDoEscritorioSumiuException extends GoogleAgendaException
{
}
