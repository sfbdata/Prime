<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\StatusDoPrazoDaMeta;
use App\Tarefa\Service\AgrupadorDeMetas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Pílula de status da meta em Minhas Metas, derivada do dado real (status + prazo) pela MESMA regra que
 * já decide o grupo (`AgrupadorDeMetas`): cada meta tem um status só, igual no modo cartões e no modo
 * lista. Rótulos e tons do desenho de 13/10 (`04 - Minhas Metas 1.0.dc.html`, JS `classe()` e `ST`).
 *
 * Corrige a divergência de "vence hoje": o cartão pintava "Pendente" de vermelho (cor do prazo) e a lista
 * de âmbar (dias < 0). Pela regra existente, prazo de hoje está na janela de 7 dias → "Vence em breve".
 */
#[CoversClass(StatusDoPrazoDaMeta::class)]
#[CoversClass(AgrupadorDeMetas::class)]
final class StatusDoPrazoDaMetaTest extends TestCase
{
    private const HOJE = '2026-10-14';

    private function meta(string $status, ?string $prazo): Tarefa
    {
        $t = new Tarefa();
        $t->setTitulo('Meta');
        $t->setDescricao('');
        $t->setStatus($status);
        $t->setPrazo($prazo === null ? null : new \DateTimeImmutable($prazo));

        return $t;
    }

    /** @return iterable<string, array{string, ?string, string, string, string}> */
    public static function casos(): iterable
    {
        yield 'atrasada (ontem)' => [Tarefa::STATUS_PENDENTE, '2026-10-13', 'atrasadas', 'Atrasada', 'danger'];
        yield 'vence hoje' => [Tarefa::STATUS_PENDENTE, '2026-10-14', 'proximas', 'Vence em breve', 'warn'];
        yield 'vence em 7 dias (borda da janela)' => [Tarefa::STATUS_PENDENTE, '2026-10-21', 'proximas', 'Vence em breve', 'warn'];
        yield 'vence em 8 dias' => [Tarefa::STATUS_PENDENTE, '2026-10-22', 'depois', 'No prazo', 'ok'];
        yield 'sem prazo' => [Tarefa::STATUS_PENDENTE, null, 'sem_prazo', 'Sem prazo', 'cinza'];
        yield 'em revisão com prazo vencido (sai da fila)' => [Tarefa::STATUS_EM_REVISAO, '2026-10-01', 'em_revisao', 'Em revisão', 'accent'];
        yield 'concluída com prazo vencido' => [Tarefa::STATUS_CONCLUIDA, '2026-10-01', 'concluidas', 'Concluída', 'ok'];
    }

    #[DataProvider('casos')]
    #[TestDox('$_dataName: balde "$balde", pílula "$rotulo" ($tom)')]
    public function testStatusDerivadoDoBalde(string $status, ?string $prazo, string $balde, string $rotulo, string $tom): void
    {
        $hoje = new \DateTimeImmutable(self::HOJE);
        $meta = $this->meta($status, $prazo);

        self::assertSame($balde, AgrupadorDeMetas::classificar($meta, $hoje));

        $s = StatusDoPrazoDaMeta::de($meta, $hoje);
        self::assertSame($rotulo, $s->rotulo);
        self::assertSame($tom, $s->tom);
    }

    #[TestDox('o agrupador continua pondo cada meta no balde que `classificar` devolve')]
    public function testAgrupadorUsaAMesmaClassificacao(): void
    {
        $hoje  = new \DateTimeImmutable(self::HOJE);
        $metas = [];
        foreach (self::casos() as [$status, $prazo, $balde]) {
            $metas[$balde][] = $this->meta($status, $prazo);
        }

        $grupos = (new AgrupadorDeMetas())->agrupar(array_merge(...array_values($metas)), \App\Tarefa\Enum\AbaMetas::RESPONSAVEL, $hoje);
        foreach ($grupos as $grupo) {
            foreach ($grupo->metas as $meta) {
                self::assertSame($grupo->chave, AgrupadorDeMetas::classificar($meta, $hoje));
            }
        }
    }
}
