<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\DTO\PastaMetasResumoOutput;
use App\Pasta\Entity\Pasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As LINHAS da aba Metas (L5 da Trilha B): ordem de criação + número local, o
 * estado que o filtro lê, a contagem de "Abertas" e o rótulo do prazo de meta
 * concluída ("concluída no prazo dd/mm/aaaa" / "concluída com N dia(s) de atraso").
 */
#[CoversClass(PastaMetasResumoOutput::class)]
final class PastaMetasResumoLinhasTest extends TestCase
{
    private function meta(
        Pasta $pasta,
        string $titulo,
        string $status,
        ?string $prazo,
        ?string $conclusao = null,
        ?string $criadaEm = null,
    ): Tarefa {
        $m = new Tarefa();
        $m->setTitulo($titulo);
        $m->setStatus($status);
        if ($prazo !== null) {
            $m->setPrazo(new \DateTimeImmutable($prazo));
        }
        if ($conclusao !== null) {
            $m->setDataConclusao(new \DateTimeImmutable($conclusao));
        }
        if ($criadaEm !== null) {
            // Sem setter: a data de criação nasce no construtor; o teste a fixa.
            (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($m, new \DateTimeImmutable($criadaEm));
        }
        $pasta->getTarefas()->add($m);

        return $m;
    }

    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function casosDoPrazoConcluido(): iterable
    {
        yield 'concluída antes do prazo'         => ['2026-10-10', '2026-10-08 17:40', 'concluída no prazo 10/10/2026'];
        yield 'concluída NO DIA do prazo (hora não conta)' => ['2026-10-10 00:00', '2026-10-10 23:59', 'concluída no prazo 10/10/2026'];
        yield '1 dia depois — singular'          => ['2026-10-10', '2026-10-11 08:00', 'concluída com 1 dia de atraso'];
        yield '12 dias depois — plural'          => ['2026-10-10', '2026-10-22 09:00', 'concluída com 12 dias de atraso'];
        yield 'sem data de conclusão: não afirma' => ['2026-10-10', null, 'prazo 10/10/2026'];
        yield 'sem prazo: sem rótulo'            => [null, '2026-10-22 09:00', null];
    }

    #[TestDox('prazo de meta concluída: $_dataName')]
    #[DataProvider('casosDoPrazoConcluido')]
    public function testRotuloDoPrazoConcluido(?string $prazo, ?string $conclusao, ?string $esperado): void
    {
        $pasta = new Pasta();
        $meta  = $this->meta($pasta, 'Meta', Tarefa::STATUS_CONCLUIDA, $prazo, $conclusao);

        self::assertSame($esperado, PastaMetasResumoOutput::rotuloPrazoConcluida($meta));

        $r = PastaMetasResumoOutput::montar($pasta, new \DateTimeImmutable('2026-10-05'));
        self::assertSame($esperado, $r->linhas[0]['prazoConcluida'], 'a linha usa o mesmo rótulo');
        self::assertSame('concluida', $r->linhas[0]['estado']);
        self::assertNull($r->linhas[0]['dias'], 'concluída não conta dias até o prazo');
    }

    #[TestDox('meta aberta não ganha rótulo de concluída, mesmo com data de conclusão gravada')]
    public function testAbertaNaoTemRotuloDeConcluida(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, 'Reaberta', Tarefa::STATUS_PENDENTE, '2026-10-10', '2026-10-08');

        $r = PastaMetasResumoOutput::montar($pasta, new \DateTimeImmutable('2026-10-05'));

        self::assertNull($r->linhas[0]['prazoConcluida']);
        self::assertSame('aberta', $r->linhas[0]['estado']);
        self::assertSame(5, $r->linhas[0]['dias']);
    }

    #[TestDox('numeração local = ordem de criação na pasta (1, 2, 3…), não a ordem da coleção nem o id')]
    public function testNumeracaoPelaOrdemDeCriacao(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, 'Terceira', Tarefa::STATUS_PENDENTE, null, null, '2026-09-03 10:00');
        $this->meta($pasta, 'Primeira', Tarefa::STATUS_CONCLUIDA, null, null, '2026-09-01 10:00');
        $this->meta($pasta, 'Segunda', Tarefa::STATUS_EM_REVISAO, null, null, '2026-09-02 10:00');

        $r = PastaMetasResumoOutput::montar($pasta, new \DateTimeImmutable('2026-10-05'));

        self::assertSame(['Primeira', 'Segunda', 'Terceira'], array_map(static fn (array $l) => $l['tarefa']->getTitulo(), $r->linhas));
        self::assertSame([1, 2, 3], array_column($r->linhas, 'numero'));
    }

    #[TestDox('estado de cada linha e contagem dos filtros: Abertas = todas as não concluídas (inclui atrasadas e em revisão)')]
    public function testEstadosEContagemDosFiltros(): void
    {
        $pasta = new Pasta();
        $this->meta($pasta, 'A', Tarefa::STATUS_PENDENTE, '2026-10-02', null, '2026-09-01');   // 3 dias de atraso
        $this->meta($pasta, 'B', Tarefa::STATUS_PENDENTE, '2026-10-05', null, '2026-09-02');   // vence hoje
        $this->meta($pasta, 'C', Tarefa::STATUS_EM_REVISAO, null, null, '2026-09-03');
        $this->meta($pasta, 'D', Tarefa::STATUS_CONCLUIDA, '2026-09-01', '2026-09-01', '2026-09-04');

        $r = PastaMetasResumoOutput::montar($pasta, new \DateTimeImmutable('2026-10-05 15:00'));

        self::assertSame(['atrasada', 'aberta', 'aberta', 'concluida'], array_column($r->linhas, 'estado'));
        self::assertSame([-3, 0, null, null], array_map(static fn (array $l) => $l['dias'], $r->linhas));
        self::assertSame(3, $r->abertas);
        self::assertSame(1, $r->atrasadas);
        self::assertSame(1, $r->concluidas);
        self::assertSame(4, $r->total);
    }

    #[TestDox('pasta sem meta: sem linhas e zero abertas')]
    public function testVazio(): void
    {
        $r = PastaMetasResumoOutput::montar(new Pasta(), new \DateTimeImmutable('2026-10-05'));

        self::assertSame([], $r->linhas);
        self::assertSame(0, $r->abertas);
    }
}
