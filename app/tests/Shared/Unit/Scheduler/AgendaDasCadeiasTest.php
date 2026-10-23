<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Scheduler;

use App\Shared\Scheduler\AgendaDasCadeias;
use App\Shared\Scheduler\AgendaDeTarefas;
use App\Shared\Scheduler\CadeiaRecorrente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * A montagem do schedule `cadeias` (spec docs/specs/scheduler-tarefas-agendadas.md, "Cadeias no Scheduler"), com
 * dublês de {@see CadeiaRecorrente}: junta as voltas de todos os domínios, sem estado, com trava própria. As voltas
 * reais de cada domínio têm teste próprio (CadeiasDaAgendaTest, CadeiasDoComercialTest) e o schedule do container,
 * o AgendadorNoContainerTest.
 */
#[CoversClass(AgendaDasCadeias::class)]
final class AgendaDasCadeiasTest extends TestCase
{
    #[TestDox('Junta as voltas de todas as cadeias declaradas, na ordem, sem conhecer as mensagens')]
    public function testJuntaAsVoltasDeTodasAsCadeias(): void
    {
        $a = RecurringMessage::every(60, new RedispatchMessage(new \stdClass(), 'async'));
        $b = RecurringMessage::every(300, new RedispatchMessage(new \ArrayObject(), 'async'));
        $c = RecurringMessage::every(900, new RedispatchMessage(new \SplQueue(), 'comercial'));

        $agenda = (new AgendaDasCadeias(new LockFactory(new InMemoryStore()), [$this->cadeia($a, $b), $this->cadeia($c)]))->getSchedule();

        self::assertSame([$a, $b, $c], array_values($agenda->getRecurringMessages()));
    }

    #[TestDox('Sem nenhuma cadeia declarada, o schedule fica vazio em vez de quebrar')]
    public function testSemCadeiaNenhuma(): void
    {
        $agenda = (new AgendaDasCadeias(new LockFactory(new InMemoryStore()), []))->getSchedule();

        self::assertSame([], $agenda->getRecurringMessages());
    }

    #[TestDox('Sem estado (stateful), com trava própria de vida curta e uma volta só por cadeia depois de um atraso')]
    public function testSemEstadoComTravaPropria(): void
    {
        // Loja que registra o prazo na chave, como a DoctrineDbalStore de produção (a InMemoryStore não registra).
        $loja = new class () implements PersistingStoreInterface {
            private bool $travada = false;

            public function save(Key $key): void
            {
                $this->travada = true;
            }

            public function delete(Key $key): void
            {
                $this->travada = false;
            }

            public function exists(Key $key): bool
            {
                return $this->travada;
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $key->reduceLifetime($ttl);
            }
        };
        $agenda = (new AgendaDasCadeias(new LockFactory($loja), []))->getSchedule();

        self::assertNull($agenda->getState(), 'o que passou da hora numa parada não roda ao voltar');
        self::assertTrue($agenda->shouldProcessOnlyLastMissedRun(), 'worker ocupado: as voltas perdidas viram uma só');
        $trava = $agenda->getLock();
        self::assertNotNull($trava);
        self::assertTrue($trava->acquire());
        self::assertEqualsWithDelta(AgendaDasCadeias::VIDA_DA_TRAVA, (float) $trava->getRemainingLifetime(), 1.0);
        self::assertNotSame(AgendaDeTarefas::TRAVA, AgendaDasCadeias::TRAVA, 'uma trava por schedule: a do default não pode segurar as cadeias');
    }

    private function cadeia(RecurringMessage ...$voltas): CadeiaRecorrente
    {
        return new class ($voltas) implements CadeiaRecorrente {
            /** @param list<RecurringMessage> $voltas */
            public function __construct(private readonly array $voltas)
            {
            }

            public function mensagens(): iterable
            {
                return $this->voltas;
            }
        };
    }
}
