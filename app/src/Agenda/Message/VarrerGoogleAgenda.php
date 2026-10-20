<?php

declare(strict_types=1);

namespace App\Agenda\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/** Volta da cadeia que sincroniza o Google Agenda de todo mundo a cada 5 min (entrega 6). */
#[AsMessage('async')]
final readonly class VarrerGoogleAgenda
{
}
