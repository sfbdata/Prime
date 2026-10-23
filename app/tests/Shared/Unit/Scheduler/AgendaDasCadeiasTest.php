<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Scheduler;

use App\Agenda\Message\VarrerGoogleAgenda;
use App\Agenda\Message\VarrerLembretesDePush;
use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\Message\VarrerAutomacoes;
use App\Shared\Scheduler\AgendaDasCadeias;
use App\Shared\Scheduler\AgendaDeTarefas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

/**
 * Contrato do schedule `cadeias` (spec docs/specs/scheduler-tarefas-agendadas.md, "Cadeias no Scheduler"): as quatro
 * varreduras que antes se reagendavam com DelayStamp, cada uma no intervalo de antes e redespachada para o transport de
 * antes (o trabalho segue com a nova tentativa e a fila de falhas de sempre). Sem estado: o que passou da hora numa
 * parada não roda ao voltar.
 */
#[CoversClass(AgendaDasCadeias::class)]
final class AgendaDasCadeiasTest extends TestCase
{
    /** @return iterable<string, array{class-string, int, string}> mensagem, intervalo em segundos, transport */
    public static function cadeiasEsperadas(): iterable
    {
        yield 'push dos lembretes' => [VarrerLembretesDePush::class, 60, 'async'];
        yield 'Google Agenda' => [VarrerGoogleAgenda::class, 300, 'async'];
        yield 'automações do Comercial' => [VarrerAutomacoes::class, 900, 'comercial'];
        yield 'expansor de campanhas' => [ExpandirCampanhas::class, 60, 'comercial'];
    }

    #[TestDox('Só as quatro cadeias, nem mais nem menos')]
    public function testAsQuatroCadeias(): void
    {
        $esperadas = array_map(static fn (array $c): string => $c[0], iterator_to_array(self::cadeiasEsperadas()));

        self::assertSame(array_values($esperadas), array_keys(AgendaDasCadeias::cadeias()));
        self::assertCount(4, $this->agenda()->getRecurringMessages());
    }

    /** @param class-string $classe */
    #[DataProvider('cadeiasEsperadas')]
    #[TestDox('$_dataName: a cada $segundos s, redespachada para o transport $transport')]
    public function testCadaCadeiaNoIntervaloENoTransportDeAntes(string $classe, int $segundos, string $transport): void
    {
        $recorrente = $this->recorrenteDa($classe);
        $gatilho    = $recorrente->getTrigger();

        self::assertInstanceOf(PeriodicalTrigger::class, $gatilho);
        $inicio = new \DateTimeImmutable('2026-10-23 10:00:00');
        $proxima = $gatilho->getNextRunDate($inicio);
        self::assertNotNull($proxima);
        $depois = $gatilho->getNextRunDate($proxima);
        self::assertNotNull($depois);
        self::assertSame($segundos, $depois->getTimestamp() - $proxima->getTimestamp());

        $mensagens = iterator_to_array($recorrente->getMessages(
            new MessageContext(AgendaDasCadeias::NOME, $recorrente->getId(), $gatilho, $inicio),
        ), false);
        self::assertCount(1, $mensagens);
        self::assertInstanceOf(RedispatchMessage::class, $mensagens[0]);
        self::assertInstanceOf($classe, $mensagens[0]->envelope);
        self::assertSame($transport, $mensagens[0]->transportNames);
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
        $agenda = (new AgendaDasCadeias(new LockFactory($loja)))->getSchedule();

        self::assertNull($agenda->getState(), 'o que passou da hora numa parada não roda ao voltar');
        self::assertTrue($agenda->shouldProcessOnlyLastMissedRun(), 'worker ocupado: as voltas perdidas viram uma só');
        $trava = $agenda->getLock();
        self::assertNotNull($trava);
        self::assertTrue($trava->acquire());
        self::assertEqualsWithDelta(AgendaDasCadeias::VIDA_DA_TRAVA, (float) $trava->getRemainingLifetime(), 1.0);
        self::assertNotSame(AgendaDeTarefas::TRAVA, AgendaDasCadeias::TRAVA, 'uma trava por schedule: a do default não pode segurar as cadeias');
    }

    private function agenda(): Schedule
    {
        return (new AgendaDasCadeias(new LockFactory(new InMemoryStore())))->getSchedule();
    }

    /** @param class-string $classe */
    private function recorrenteDa(string $classe): RecurringMessage
    {
        $indice = array_search($classe, array_keys(AgendaDasCadeias::cadeias()), true);
        self::assertIsInt($indice, "Cadeia {$classe} fora da agenda.");

        return array_values($this->agenda()->getRecurringMessages())[$indice];
    }
}
