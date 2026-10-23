<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Scheduler;

use App\Shared\Scheduler\VoltaRecorrente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * A volta das cadeias ancorada no relógio (spec scheduler-tarefas-agendadas, "Cadeias no Scheduler"): o worker reinicia
 * de hora em hora, e a varredura de 15 min não pode recomeçar a contagem a cada subida. Prova com o MessageGenerator
 * do Symfony, sem estado e com processOnlyLastMissedRun, como o schedule `cadeias`.
 */
#[CoversClass(VoltaRecorrente::class)]
final class VoltaRecorrenteTest extends TestCase
{
    private const FUSO = 'America/Sao_Paulo';

    #[TestDox('Dois reinícios do worker em instantes diferentes disparam a volta de 15 min no mesmo horário do relógio')]
    public function testDoisReiniciosDaoOMesmoProximoHorario(): void
    {
        foreach (['2026-10-23 10:03:10', '2026-10-23 10:07:50', '2026-10-23 10:14:59'] as $subida) {
            self::assertSame(['2026-10-23 10:15:00'], $this->disparos(900, $subida, '2026-10-23 10:15:00'), "subida às {$subida}");
        }
    }

    #[TestDox('Com a âncora no passado não há enxurrada: a primeira volta depois da subida é uma só, no próximo múltiplo')]
    public function testSemEnxurradaDepoisDaSubida(): void
    {
        self::assertSame([], $this->disparos(900, '2026-10-23 10:03:10', '2026-10-23 10:14:59'), 'nada antes do próximo múltiplo');
        self::assertSame(['2026-10-23 10:04:00'], $this->disparos(60, '2026-10-23 10:03:10', '2026-10-23 10:04:00'));
        // Worker parado 10 min entre uma consulta e outra: só a última volta perdida sai.
        self::assertSame(['2026-10-23 10:14:00'], $this->disparos(60, '2026-10-23 10:03:10', '2026-10-23 10:14:30'));
    }

    #[TestDox('A mensagem vai redespachada para o transport pedido')]
    public function testRedespachaParaOTransport(): void
    {
        $volta = VoltaRecorrente::aCada(60, new \stdClass(), 'comercial');
        $gatilho = $volta->getTrigger();
        $contexto = new MessageContext('cadeias', $volta->getId(), $gatilho, new \DateTimeImmutable());
        $mensagens = iterator_to_array($volta->getMessages($contexto), false);

        self::assertCount(1, $mensagens);
        self::assertInstanceOf(RedispatchMessage::class, $mensagens[0]);
        self::assertSame('comercial', $mensagens[0]->transportNames);
    }

    /**
     * Um processo novo (a volta montada de novo, como no boot do worker) que sobe em $subida e consulta o gerador de
     * novo em $consulta. Devolve os horários (Brasília) das voltas geradas na consulta.
     *
     * @return list<string>
     */
    private function disparos(int $segundos, string $subida, string $consulta): array
    {
        $relogio = new MockClock(new \DateTimeImmutable($subida, new \DateTimeZone(self::FUSO)));
        $agenda  = (new Schedule())->add(VoltaRecorrente::aCada($segundos, new \stdClass(), 'async'))->processOnlyLastMissedRun(true);
        $provedor = new class ($agenda) implements ScheduleProviderInterface {
            public function __construct(private readonly Schedule $agenda)
            {
            }

            public function getSchedule(): Schedule
            {
                return $this->agenda;
            }
        };
        $gerador = new MessageGenerator($provedor, 'cadeias', $relogio);

        self::assertSame([], iterator_to_array($gerador->getMessages(), false), 'na subida não sai nada atrasado');
        $relogio->modify($consulta);
        $horarios = [];
        foreach ($gerador->getMessages() as $contexto => $mensagem) {
            $horarios[] = $contexto->triggeredAt->setTimezone(new \DateTimeZone(self::FUSO))->format('Y-m-d H:i:s');
        }

        return $horarios;
    }
}
