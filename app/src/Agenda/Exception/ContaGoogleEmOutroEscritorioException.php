<?php

declare(strict_types=1);

namespace App\Agenda\Exception;

/**
 * A conta Google já está ligada à Agenda de outro escritório. A agenda pessoal entra só no primeiro: o
 * "Só eu" aparece na auditoria de quem administra, e a agenda de alguém não pode vazar para um empregador
 * (decisão do coordenador, 19/10/2026).
 */
final class ContaGoogleEmOutroEscritorioException extends GoogleAgendaException
{
}
