<?php

declare(strict_types=1);

namespace App\Agenda\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Uma volta da varredura do push dos lembretes. Vai para a fila `async`, a mesma que o worker de produção
 * já consome (docker-compose.prod.yml), sem cron. Cada volta agenda a próxima para dali a 60 s.
 */
#[AsMessage('async')]
final readonly class VarrerLembretesDePush
{
}
