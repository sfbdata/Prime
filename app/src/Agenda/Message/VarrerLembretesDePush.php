<?php

declare(strict_types=1);

namespace App\Agenda\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Uma volta da varredura do push dos lembretes, na fila `async`. Disparada a cada 60 s pelo schedule `cadeias`
 * (App\Shared\Scheduler\AgendaDasCadeias), que o worker de produção consome junto com as filas.
 */
#[AsMessage('async')]
final readonly class VarrerLembretesDePush
{
}
