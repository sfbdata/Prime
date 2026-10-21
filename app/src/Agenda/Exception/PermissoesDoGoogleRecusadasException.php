<?php

declare(strict_types=1);

namespace App\Agenda\Exception;

/**
 * O Google concedeu permissões diferentes das pedidas para a agenda. Leva a autorização recebida e a conta, para quem
 * conecta decidir se revoga (não revoga a conta ligada em outro escritório: derrubaria a de lá).
 */
final class PermissoesDoGoogleRecusadasException extends GoogleAgendaException
{
    public function __construct(
        public readonly string $refresh,
        public readonly string $conta,
    ) {
        parent::__construct('O Google concedeu permissões diferentes das pedidas para a agenda.');
    }
}
