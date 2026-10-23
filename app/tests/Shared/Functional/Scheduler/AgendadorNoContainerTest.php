<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Scheduler;

use App\Shared\Scheduler\AgendaDasCadeias;
use App\Shared\Scheduler\AgendaDeTarefas;
use App\Shared\Scheduler\AvisoDeFalhaDoAgendador;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Scheduler\Event\FailureEvent;

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
        foreach (AgendaDasCadeias::cadeias() as $classe => $cadeia) {
            self::assertStringContainsString($classe . ' via ' . $cadeia['transport'], $saida);
        }
        self::assertTrue(static::getContainer()->has('messenger.redispatch_message_handler'), 'sem o handler do RedispatchMessage, a volta não chega ao transport');
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
