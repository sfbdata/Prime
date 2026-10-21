<?php

declare(strict_types=1);

namespace App\Agenda\Exception;

/**
 * O Google recusou este evento de vez (400, ou 403 que não é limite de uso: convite de outro organizador,
 * id de outra conta). Só ele fica de fora; a sincronia segue com os outros.
 */
final class EventoRecusadoPeloGoogleException extends GoogleAgendaException
{
}
