<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Scheduler;

use App\Shared\Scheduler\AgendaDeTarefas;
use Cron\CronExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

/**
 * Contrato da lista de tarefas agendadas (spec docs/specs/scheduler-tarefas-agendadas.md, "Tarefas").
 *
 * Cada tarefa: o comando exato (com as opções que o crontab passava), a expressão cron em Brasília e a
 * prova de que ela dispara nos MESMOS instantes que a linha antiga do crontab, em UTC. Mudou um
 * horário, um comando ou o fuso: este teste quebra e a spec precisa mudar junto.
 */
#[CoversClass(AgendaDeTarefas::class)]
final class AgendaDeTarefasTest extends TestCase
{
    /**
     * nome => [comando completo, cron novo (Brasília), cron antigo do crontab (UTC)].
     *
     * @return array<string, array{string, string, string}>
     */
    private static function esperadas(): array
    {
        return [
            'app:purgar-dados-expirados' => ['app:purgar-dados-expirados --force --no-interaction', '0 0 * * *', '0 3 * * *'],
            'app:importar-indices-monetarios' => ['app:importar-indices-monetarios --no-interaction', '0 1 11 * *', '0 4 11 * *'],
            'app:djen:sincronizar' => ['app:djen:sincronizar --no-interaction', '0 2 * * *', '0 5 * * *'],
            'app:perfil:resumo-diario' => ['app:perfil:resumo-diario --no-interaction', '0 8 * * *', '0 11 * * *'],
            'app:push:resumo-semanal-cliente' => ['app:push:resumo-semanal-cliente --no-interaction', '0 8 * * 1', '0 11 * * 1'],
            'app:pasta:cobrar-checklist' => ['app:pasta:cobrar-checklist --no-interaction', '0 9 * * 1-5', '0 12 * * 1-5'],
            'app:pastas:relatorio-sem-processo' => ['app:pastas:relatorio-sem-processo --no-interaction', '5 10 * * *', '5 13 * * *'],
            'app:pastas:relatorio-pastas-paradas' => ['app:pastas:relatorio-pastas-paradas --no-interaction', '15 10 * * *', '15 13 * * *'],
            // A recomendação antiga era 02h55 UTC (23h55 de Brasília do dia anterior): mesma hora.
            'app:dashboard:fotografar' => ['app:dashboard:fotografar --no-interaction', '55 23 * * *', '55 2 * * *'],
            'app:sync:reconciliar' => ['app:sync:reconciliar --tenant-id=1 --usuario-id=1 --modo=enviar --no-interaction', '0 * * * *', '0 * * * *'],
        ];
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function tarefasEsperadas(): iterable
    {
        foreach (self::esperadas() as $nome => [$comando, $cron, $cronUtc]) {
            yield $nome => [$nome, $comando, $cron, $cronUtc];
        }
    }

    public function testAListaTemExatamenteAsTarefasDaSpec(): void
    {
        self::assertSame(array_keys(self::esperadas()), array_keys(AgendaDeTarefas::tarefas()));
        self::assertCount(\count(self::esperadas()), $this->agenda()->getRecurringMessages());
    }

    #[DataProvider('tarefasEsperadas')]
    public function testCadaTarefaRodaOComandoNaHoraDeBrasiliaEquivalenteAoCrontabAntigo(
        string $nome,
        string $comando,
        string $cron,
        string $cronUtcAntigo,
    ): void {
        $recorrente = $this->recorrenteDo($nome);
        $gatilho    = $recorrente->getTrigger();

        self::assertInstanceOf(CronExpressionTrigger::class, $gatilho);
        self::assertSame($cron, (string) $gatilho);

        $mensagens = iterator_to_array($recorrente->getMessages(
            new MessageContext('default', $recorrente->getId(), $gatilho, new \DateTimeImmutable()),
        ), false);
        self::assertCount(1, $mensagens);
        self::assertInstanceOf(RunCommandMessage::class, $mensagens[0]);
        self::assertSame($comando, $mensagens[0]->input);
        self::assertTrue($mensagens[0]->throwOnFailure, 'Código de saída diferente de 0 precisa virar falha (aviso).');

        // Mesmos instantes que a linha antiga do crontab (em UTC), ao longo de 5 semanas, a cada 7 horas:
        // pega todos os dias da semana, a virada do dia em Brasília e o dia 11 do mês.
        $antigo = new CronExpression($cronUtcAntigo);
        $utc    = new \DateTimeZone('UTC');
        $agora  = new \DateTimeImmutable('2026-10-05 00:30:00', $utc);
        for ($i = 0; $i < 120; ++$i) {
            $esperado = \DateTimeImmutable::createFromMutable($antigo->getNextRunDate($agora, 0, false, 'UTC'));
            $novo     = $gatilho->getNextRunDate($agora);

            self::assertNotNull($novo);
            self::assertSame(
                $esperado->setTimezone($utc)->format(\DATE_ATOM),
                $novo->setTimezone($utc)->format(\DATE_ATOM),
                sprintf('%s: a partir de %s', $nome, $agora->format(\DATE_ATOM)),
            );
            $agora = $agora->modify('+7 hours');
        }
    }

    public function testOFusoEDeBrasiliaEm9hDaManhaDoChecklist(): void
    {
        // Segunda, 26/10/2026, 08h59 em Brasília: a próxima cobrança é 09h00 de Brasília (12h00 UTC).
        $gatilho = $this->recorrenteDo('app:pasta:cobrar-checklist')->getTrigger();
        $proxima = $gatilho->getNextRunDate(new \DateTimeImmutable('2026-10-26 08:59:00', new \DateTimeZone(AgendaDeTarefas::FUSO)));

        self::assertNotNull($proxima);
        self::assertSame('2026-10-26T12:00:00+00:00', $proxima->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function testOScheduleEStatefulTravadoEProcessaSoAUltimaExecucaoPerdida(): void
    {
        $estado = new ArrayAdapter();
        $agenda = (new AgendaDeTarefas($estado, new LockFactory(new InMemoryStore())))->getSchedule();

        self::assertSame($estado, $agenda->getState());
        self::assertNotNull($agenda->getLock());
        self::assertTrue($agenda->shouldProcessOnlyLastMissedRun());
    }

    private function agenda(): Schedule
    {
        return (new AgendaDeTarefas(new ArrayAdapter(), new LockFactory(new InMemoryStore())))->getSchedule();
    }

    private function recorrenteDo(string $nome): RecurringMessage
    {
        $indice      = array_search($nome, array_keys(AgendaDeTarefas::tarefas()), true);
        $recorrentes = array_values($this->agenda()->getRecurringMessages());

        self::assertIsInt($indice, "Tarefa {$nome} fora da agenda.");

        return $recorrentes[$indice];
    }
}
