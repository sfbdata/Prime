<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Scheduler\CadeiaRecorrente;
use PHPUnit\Framework\Assert;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

/** Lê as voltas de uma {@see CadeiaRecorrente} como o Scheduler as gera: mensagem de dentro, intervalo e transport. */
final class CadeiaRecorrenteConferida
{
    /** @return list<array{class-string, int, string|array<string>}> classe da mensagem, segundos entre voltas, transport */
    public static function voltas(CadeiaRecorrente $cadeia): array
    {
        $voltas = [];
        $inicio = new \DateTimeImmutable('2026-10-23 10:00:00');
        foreach ($cadeia->mensagens() as $recorrente) {
            $gatilho = $recorrente->getTrigger();
            Assert::assertInstanceOf(PeriodicalTrigger::class, $gatilho);
            $primeira = $gatilho->getNextRunDate($inicio);
            Assert::assertNotNull($primeira);
            $segunda = $gatilho->getNextRunDate($primeira);
            Assert::assertNotNull($segunda);

            $mensagens = iterator_to_array($recorrente->getMessages(new MessageContext('cadeias', $recorrente->getId(), $gatilho, $inicio)), false);
            Assert::assertCount(1, $mensagens);
            Assert::assertInstanceOf(RedispatchMessage::class, $mensagens[0]);
            $voltas[] = [$mensagens[0]->envelope::class, $segunda->getTimestamp() - $primeira->getTimestamp(), $mensagens[0]->transportNames];
        }

        return $voltas;
    }
}
