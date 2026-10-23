<?php

declare(strict_types=1);

namespace App\Shared\Scheduler;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * As varreduras periódicas de um domínio, entregues ao schedule `cadeias` ({@see AgendaDasCadeias}). Cada domínio
 * declara as suas numa classe que implementa esta interface (a tag vem sozinha): o Shared monta o schedule sem
 * conhecer nenhuma mensagem de domínio.
 *
 * Regra de cada volta: `RecurringMessage::every(<intervalo>, new RedispatchMessage(new X(), '<transport>'))`, com o
 * transport em que o trabalho já rodava, para manter a nova tentativa e a fila de falhas dele.
 */
#[AutoconfigureTag(self::TAG)]
interface CadeiaRecorrente
{
    public const TAG = 'app.scheduler.cadeia_recorrente';

    /** @return iterable<RecurringMessage> */
    public function mensagens(): iterable;
}
