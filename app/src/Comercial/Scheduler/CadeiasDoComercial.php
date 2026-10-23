<?php

declare(strict_types=1);

namespace App\Comercial\Scheduler;

use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\MessageHandler\ExpandirCampanhasHandler;
use App\Comercial\MessageHandler\VarrerAutomacoesHandler;
use App\Shared\Scheduler\CadeiaRecorrente;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * As varreduras do Comercial no schedule `cadeias`: as automações e a recuperação de envios (a cada 15 min) e o
 * expansor de campanhas (a cada 60 s), as duas na fila `comercial`, onde já rodavam. O intervalo mora no handler, ao
 * lado da trava de cada uma no banco.
 */
final class CadeiasDoComercial implements CadeiaRecorrente
{
    public const TRANSPORT = 'comercial';

    public function mensagens(): iterable
    {
        yield RecurringMessage::every(VarrerAutomacoesHandler::INTERVALO_SEGUNDOS, new RedispatchMessage(new VarrerAutomacoes(), self::TRANSPORT));
        yield RecurringMessage::every(ExpandirCampanhasHandler::INTERVALO_SEGUNDOS, new RedispatchMessage(new ExpandirCampanhas(), self::TRANSPORT));
    }
}
