<?php

declare(strict_types=1);

namespace App\Agenda\Scheduler;

use App\Agenda\Message\VarrerGoogleAgenda;
use App\Agenda\Message\VarrerLembretesDePush;
use App\Agenda\Repository\VarreduraDePushRepository;
use App\Agenda\Repository\VarreduraDoGoogleRepository;
use App\Shared\Scheduler\CadeiaRecorrente;
use App\Shared\Scheduler\VoltaRecorrente;

/**
 * As varreduras da Agenda no schedule `cadeias`: o push dos lembretes (a cada 60 s) e a sincronia do Google Agenda (a
 * cada 5 min), as duas na fila `async`, onde já rodavam. O intervalo mora ao lado da trava de cada uma no banco.
 */
final class CadeiasDaAgenda implements CadeiaRecorrente
{
    public const TRANSPORT = 'async';

    public function mensagens(): iterable
    {
        yield VoltaRecorrente::aCada(VarreduraDePushRepository::INTERVALO_SEGUNDOS, new VarrerLembretesDePush(), self::TRANSPORT);
        yield VoltaRecorrente::aCada(VarreduraDoGoogleRepository::INTERVALO_SEGUNDOS, new VarrerGoogleAgenda(), self::TRANSPORT);
    }
}
