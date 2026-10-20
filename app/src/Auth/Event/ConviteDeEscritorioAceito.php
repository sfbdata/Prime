<?php

declare(strict_types=1);

namespace App\Auth\Event;

/**
 * Convite de escritório aceito (com ou sem conta, também o de primário e a reativação). Despachado depois do último flush (spec `comercial-e-emails.md` §3.5). Só ids e o necessário: quem ouve relê o resto. Um ouvinte nunca pode
 * derrubar o fluxo de Auth: os do Comercial capturam qualquer exceção.
 */
final class ConviteDeEscritorioAceito
{
    public function __construct(
        public readonly int $userId,
        public readonly int $tenantId,
        public readonly bool $comoPrimario,
    ) {
    }
}
