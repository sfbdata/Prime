<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Scheduler;

use App\Agenda\Message\VarrerGoogleAgenda;
use App\Agenda\Message\VarrerLembretesDePush;
use App\Comercial\Message\ExpandirCampanhas;
use App\Comercial\Message\VarrerAutomacoes;
use App\Shared\Scheduler\AgendaDasCadeias;
use App\Shared\Scheduler\AgendaDeTarefas;
use App\Shared\Scheduler\AvisoDeFalhaDoAgendador;
use App\Shared\Scheduler\CadeiaRecorrente;
use App\Tests\Shared\CadeiaRecorrenteConferida;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * O agendador montado pelo contêiner de verdade (config/packages/lock.yaml e scheduler.yaml): o schedule
 * `default` (transport scheduler_default) lista as tarefas da agenda, o `cadeias` (transport scheduler_cadeias)
 * lista as quatro varreduras, e o aviso de falha está ligado ao FailureEvent. As listas em si são contrato do
 * AgendaDeTarefasTest e do AgendaDasCadeiasTest; aqui só se prova que o Symfony as enxerga.
 */
#[CoversClass(AgendaDeTarefas::class)]
#[CoversClass(AgendaDasCadeias::class)]
#[CoversClass(AvisoDeFalhaDoAgendador::class)]
final class AgendadorNoContainerTest extends KernelTestCase
{
    /** As quatro varreduras: mensagem, segundos entre voltas e transport (declaradas em Agenda e Comercial). */
    private const CADEIAS = [
        [VarrerLembretesDePush::class, 60, 'async'],
        [VarrerGoogleAgenda::class, 300, 'async'],
        [VarrerAutomacoes::class, 900, 'comercial'],
        [ExpandirCampanhas::class, 60, 'comercial'],
    ];

    public function testDebugSchedulerListaTodasAsTarefasNoScheduleDefault(): void
    {
        $aplicacao = new Application(self::bootKernel());
        $tester    = new CommandTester($aplicacao->find('debug:scheduler'));

        $tester->execute(['schedule' => ['default']]);

        $tester->assertCommandIsSuccessful();
        $saida = $tester->getDisplay();
        foreach (AgendaDeTarefas::tarefas() as $tarefa) {
            self::assertStringContainsString($tarefa['comando'], $saida);
        }
        // Próxima execução no fuso de Brasília (-0300), não em UTC.
        self::assertStringContainsString('-0300', $saida);
    }

    public function testDebugSchedulerMostraOsDoisSchedules(): void
    {
        $aplicacao = new Application(self::bootKernel());
        $tester    = new CommandTester($aplicacao->find('debug:scheduler'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $saida = $tester->getDisplay();
        self::assertStringContainsString('default', $saida);
        self::assertStringContainsString(AgendaDasCadeias::NOME, $saida);
        foreach (self::CADEIAS as [$classe, , $transport]) {
            self::assertStringContainsString($classe . ' via ' . $transport, $saida);
        }
        self::assertTrue(static::getContainer()->has('messenger.redispatch_message_handler'), 'sem o handler do RedispatchMessage, a volta não chega ao transport');
    }

    public function testOScheduleRealTemAsQuatroCadeiasDosDominios(): void
    {
        self::bootKernel();
        $agenda = static::getContainer()->get(AgendaDasCadeias::class)->getSchedule();

        $voltas = [];
        foreach ($agenda->getRecurringMessages() as $recorrente) {
            $voltas = array_merge($voltas, CadeiaRecorrenteConferida::voltas(new class ($recorrente) implements CadeiaRecorrente {
                public function __construct(private readonly RecurringMessage $volta)
                {
                }

                public function mensagens(): iterable
                {
                    yield $this->volta;
                }
            }));
        }
        sort($voltas);
        $esperadas = self::CADEIAS;
        sort($esperadas);

        self::assertSame($esperadas, $voltas);
    }

    public function testAvisoDeFalhaEstaLigadoAoFailureEvent(): void
    {
        self::bootKernel();
        $ouvintes = static::getContainer()->get('event_dispatcher')->getListeners(FailureEvent::class);

        $classes = array_map(
            static fn (mixed $ouvinte): string => \is_array($ouvinte) && \is_object($ouvinte[0]) ? $ouvinte[0]::class : get_debug_type($ouvinte),
            $ouvintes,
        );

        self::assertContains(AvisoDeFalhaDoAgendador::class, $classes);
    }
}
