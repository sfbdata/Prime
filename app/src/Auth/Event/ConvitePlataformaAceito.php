<?php

declare(strict_types=1);

namespace App\Auth\Event;

/**
 * Convite de plataforma aceito (conta sem escritório). Despachado depois do último flush (spec `comercial-e-emails.md` §3.5). Só ids e o necessário: quem ouve relê o resto. Um ouvinte nunca pode
 * derrubar o fluxo de Auth: os do Comercial capturam qualquer exceção.
 */
final class ConvitePlataformaAceito
{
    public function __construct(
        public readonly int $userId,
    ) {
    }
}
