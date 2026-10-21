<?php

declare(strict_types=1);

namespace App\Agenda\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/** Sincroniza o Google Agenda de uma conexão (uma pessoa num escritório). */
#[AsMessage('async')]
final readonly class SincronizarGoogleDaPessoa
{
    public function __construct(
        public int $conexaoId,
        public int $tentativa = 1,
    ) {
    }
}
