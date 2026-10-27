<?php

declare(strict_types=1);

namespace App\Shared\Scheduler;

use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * Monta uma volta do schedule `cadeias` ({@see CadeiaRecorrente}): a mensagem redespachada para o transport em que o
 * trabalho já rodava, a cada N segundos, **ancorada num relógio fixo** ({@see self::ANCORA}, em Brasília).
 *
 * Por que a âncora: sem `from`, o PeriodicalTrigger conta o intervalo a partir do primeiro disparo calculado, que no
 * schedule sem estado é a subida do processo. O worker reinicia de hora em hora (`--time-limit=3600`) ou antes (memória),
 * então uma volta de 15 min recomeçaria a contagem a cada reinício e poderia nunca chegar a rodar. Com a âncora no
 * passado, o próximo disparo é sempre o próximo múltiplo do intervalo depois de agora (conta direta, sem percorrer as
 * voltas passadas): 900 s cai em :00, :15, :30 e :45; 60 s, no segundo 0 de cada minuto; qualquer que seja a hora em
 * que o worker subiu.
 */
final class VoltaRecorrente
{
    public const ANCORA = '2026-01-01 00:00:00';

    public static function aCada(int $segundos, object $mensagem, string $transport): RecurringMessage
    {
        return RecurringMessage::every(
            $segundos,
            new RedispatchMessage($mensagem, $transport),
            new \DateTimeImmutable(self::ANCORA, new \DateTimeZone(AgendaDeTarefas::FUSO)),
        );
    }
}
