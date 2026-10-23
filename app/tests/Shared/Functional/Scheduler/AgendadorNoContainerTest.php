<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Scheduler;

use App\Shared\Scheduler\AgendaDeTarefas;
use App\Shared\Scheduler\AvisoDeFalhaDoAgendador;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Scheduler\Event\FailureEvent;

/**
 * O agendador montado pelo contêiner de verdade (config/packages/lock.yaml e scheduler.yaml): o schedule
 * `default` (transport scheduler_default) lista as tarefas da agenda e o aviso de falha está ligado ao
 * FailureEvent. A lista em si é contrato do AgendaDeTarefasTest; aqui só se prova que o Symfony a enxerga.
 */
#[CoversClass(AgendaDeTarefas::class)]
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
