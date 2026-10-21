<?php

declare(strict_types=1);

namespace App\Agenda\Exception;

/** O Google recusou o refresh token (a pessoa revogou o acesso na conta): precisa conectar de novo. */
final class AutorizacaoDoGoogleRecusadaException extends GoogleAgendaException
{
}
