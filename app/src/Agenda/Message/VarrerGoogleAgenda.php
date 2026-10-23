<?php

declare(strict_types=1);

namespace App\Agenda\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/** Volta que sincroniza o Google Agenda de todo mundo, disparada a cada 5 min pelo schedule `cadeias` (entrega 6). */
#[AsMessage('async')]
final readonly class VarrerGoogleAgenda
{
}
