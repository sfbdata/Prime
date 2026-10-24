<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Scheduler;

use App\Shared\Scheduler\AgendaDeTarefas;
use App\Shared\Scheduler\NomeDaTarefaAgendada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NomeDaTarefaAgendada::class)]
final class NomeDaTarefaAgendadaTest extends KernelTestCase
{
    #[TestDox('cada tarefa da agenda tem nome para o Master; comando desconhecido vira "Tarefa agendada"')]
    public function testNomes(): void
    {
        self::bootKernel();
        $nome = new NomeDaTarefaAgendada(static::getContainer()->get(TranslatorInterface::class));

        self::assertSame('Captação das publicações do DJEN', $nome->de('app:djen:sincronizar --dias=2 --no-interaction'));
        self::assertSame('Envio para o Google Drive', $nome->de('app:sync:reconciliar'));
        self::assertSame('Tarefa agendada', $nome->de('app:outra:coisa'));
    }

    #[TestDox('nenhuma tarefa da agenda cai no nome genérico')]
    public function testTodaTarefaDaAgendaTemNome(): void
    {
        self::bootKernel();
        $nome = new NomeDaTarefaAgendada(static::getContainer()->get(TranslatorInterface::class));
        $agenda = new \ReflectionClassConstant(AgendaDeTarefas::class, 'TAREFAS');

        foreach (array_keys((array) $agenda->getValue()) as $comando) {
            self::assertNotSame('Tarefa agendada', $nome->de($comando), $comando);
        }
    }
}
