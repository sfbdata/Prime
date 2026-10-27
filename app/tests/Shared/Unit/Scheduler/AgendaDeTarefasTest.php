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
 * prova de que ela dispara nos MESMOS instantes que a linha do crontab real da VPS (conferido em 23/10), em UTC. Mudou um
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
            // Nova, não estava no crontab: retenção de 12 meses da BlueJus IA (decisão de 24/10/2026).
            'app:inteligencia:expirar-conteudo' => ['app:inteligencia:expirar-conteudo --force --no-interaction', '20 0 * * *', '20 3 * * *'],
            // Nova: retenção de 90 dias dos eventos de desempenho do Master (LGPD).
            'app:desempenho:purgar' => ['app:desempenho:purgar --no-interaction', '40 0 * * *', '40 3 * * *'],
            // Não estava no crontab real: o UTC é o da linha do DEPLOY.md, ligada por decisão do dono (23/10).
            'app:importar-indices-monetarios' => ['app:importar-indices-monetarios --no-interaction', '0 1 11 * *', '0 4 11 * *'],
            'app:djen:sincronizar' => ['app:djen:sincronizar --dias=2 --no-interaction', '0 3 * * *', '0 6 * * *'],
            // Novas (espelho R2, P2.5 e P2.7): o "UTC antigo" é a mesma hora em UTC, para a prova do fuso.
            'armazenamento:reconciliar-espelho' => ['armazenamento:reconciliar-espelho --se-ligado --fatia=auto --no-interaction', '30 3 * * *', '30 6 * * *'],
            'armazenamento:limpar-replicacao' => ['armazenamento:limpar-replicacao --se-ligado --no-interaction', '30 4 * * *', '30 7 * * *'],
            'app:push:resumo-semanal-cliente' => ['app:push:resumo-semanal-cliente --no-interaction', '0 8 * * 1', '0 11 * * 1'],
            'app:pastas:relatorio-sem-processo' => ['app:pastas:relatorio-sem-processo --no-interaction', '5 10 * * *', '5 13 * * *'],
            'app:pastas:relatorio-pastas-paradas' => ['app:pastas:relatorio-pastas-paradas --no-interaction', '15 10 * * *', '15 13 * * *'],
            // A recomendação antiga era 02h55 UTC (23h55 de Brasília do dia anterior): mesma hora.
            'app:dashboard:fotografar' => ['app:dashboard:fotografar --no-interaction', '55 23 * * *', '55 2 * * *'],
            // Nova (Contribua C3): era a linha de crontab da conciliação das assinaturas, agora no Scheduler.
            'app:contribua:conciliar-assinaturas' => ['app:contribua:conciliar-assinaturas --no-interaction', '15 * * * *', '15 * * * *'],
            'armazenamento:replicar-espelho' => ['armazenamento:replicar-espelho --se-ligado --limite=50 --no-interaction', '* * * * *', '* * * * *'],
            // Sem --tenant-id: todos os escritórios com Drive conectado (decisão do dono, 24/10/2026).
            'app:sync:reconciliar' => ['app:sync:reconciliar --usuario-id=1 --modo=enviar --no-interaction', '0 * * * *', '0 * * * *'],
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

    public function testOFusoEDeBrasiliaNasTresDaManhaDoDjen(): void
    {
        // Segunda, 26/10/2026, 02h59 em Brasília: a próxima captação é 03h00 de Brasília (06h00 UTC).
        $gatilho = $this->recorrenteDo('app:djen:sincronizar')->getTrigger();
        $proxima = $gatilho->getNextRunDate(new \DateTimeImmutable('2026-10-26 02:59:00', new \DateTimeZone(AgendaDeTarefas::FUSO)));

        self::assertNotNull($proxima);
        self::assertSame('2026-10-26T06:00:00+00:00', $proxima->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function testAsTarefasDoEspelhoSoAgemSeLigadoEOShaVaiPorFatia(): void
    {
        $doEspelho = array_filter(AgendaDeTarefas::tarefas(), static fn (string $nome): bool => str_starts_with($nome, 'armazenamento:'), \ARRAY_FILTER_USE_KEY);

        self::assertCount(3, $doEspelho);
        foreach ($doEspelho as $nome => $tarefa) {
            self::assertStringContainsString(' --se-ligado ', $tarefa['comando'], $nome . ' só age com o espelho ligado (P2.5).');
        }
        // Nenhuma noite confere o SHA do acervo inteiro: a diária vai pela fatia do dia, sem tarefa semanal.
        self::assertStringContainsString(' --fatia=auto ', $doEspelho['armazenamento:reconciliar-espelho']['comando']);
        self::assertStringNotContainsString('--sem-sha', $doEspelho['armazenamento:reconciliar-espelho']['comando']);
    }

    public function testTarefasQueNuncaRodaramEmProducaoFicamDesligadas(): void
    {
        foreach (['app:perfil:resumo-diario', 'app:pasta:cobrar-checklist'] as $desligada) {
            self::assertArrayNotHasKey($desligada, AgendaDeTarefas::tarefas(), $desligada . ' só liga por decisão do dono.');
        }
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
