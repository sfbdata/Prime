<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\DTO\ComparacaoDaPessoaOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\DTO\MetricaDaPessoaOutput;
use App\Dashboard\Inteligencia\DiagnosticoDaPessoa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiagnosticoDaPessoa::class)]
#[CoversClass(ComparacaoDaPessoaOutput::class)]
#[Group('dashboard')]
final class DiagnosticoDaPessoaTest extends TestCase
{
    /** @param list<string> $zeradas */
    private function linha(int $metas = 10, int $ativas = 4, int $vencidas = 2, int $prazos = 1, ?int $metasAnt = 8, ?int $vencAnt = 1, array $zeradas = []): LinhaAdvogadoDashboardOutput
    {
        return new LinhaAdvogadoDashboardOutput(
            userId: 5,
            nomeAdvogado: 'Ana Souza',
            cargoNome: null,
            fotoUrl: null,
            totalMetas: $metas,
            metasAtivas: $ativas,
            metasVencidas: $vencidas,
            prazosProximos: $prazos,
            totalDemandas: 3,
            demandasAtivas: 2,
            pastasCriadas: 1,
            totalMetasAnterior: $metasAnt,
            totalDemandasAnterior: null,
            metasVencidasAnterior: $vencAnt,
            colunasZeradas: $zeradas,
        );
    }

    #[TestDox('Os motivos são só fatos dos números, e a carga que subiu vem com os dois lados')]
    public function testMotivos(): void
    {
        $m = DiagnosticoDaPessoa::motivos($this->linha(), 6, 'Ana');

        self::assertSame([
            '10 metas criadas no período: 6 concluídas e 4 ativas (60% concluído).',
            '2 metas com prazo já vencido continuam abertas.',
            '1 meta vence nos próximos dias.',
            'A carga recebida subiu 25% frente ao período anterior (8 para 10).',
        ], $m);
    }

    #[TestDox('Sem meta no período não há percentual (nada de divisão por zero) nem comparação de carga')]
    public function testSemMetas(): void
    {
        $m = DiagnosticoDaPessoa::motivos($this->linha(0, 0, 0, 0, 0, null), 0, 'Ana');

        self::assertSame(['Nenhuma meta foi criada para Ana no período.'], $m);
    }

    #[TestDox('A comparação só tem a linha que tem o número do passado; coluna zerada fica sem a de atrasadas')]
    public function testComparacoes(): void
    {
        $c = DiagnosticoDaPessoa::comparacoes($this->linha());
        self::assertSame(['recebidas', 'atrasadas'], array_map(static fn (ComparacaoDaPessoaOutput $x): string => $x->chave, $c));
        self::assertSame(25, $c[0]->variacao());
        self::assertSame(100, $c[1]->variacao());

        self::assertSame([], DiagnosticoDaPessoa::comparacoes($this->linha(metasAnt: null, vencAnt: null)));
        self::assertCount(1, DiagnosticoDaPessoa::comparacoes($this->linha(zeradas: ['metas_vencidas'])));
    }

    #[TestDox('Com Zerar em metas e demandas, a comparação e a frase da carga somem; sem Zerar aparecem')]
    public function testZerarEmMetasEDemandas(): void
    {
        $com = new LinhaAdvogadoDashboardOutput(5, 'Ana Souza', null, null, 10, 4, 0, 0, 3, 2, 1, 8, 2, null, colunasZeradas: []);
        self::assertSame(['recebidas', 'demandas'], array_map(static fn (ComparacaoDaPessoaOutput $x): string => $x->chave, DiagnosticoDaPessoa::comparacoes($com)));
        self::assertStringContainsString('A carga recebida subiu', implode(' ', DiagnosticoDaPessoa::motivos($com, 6, 'Ana')));

        $zerada = new LinhaAdvogadoDashboardOutput(5, 'Ana Souza', null, null, 10, 4, 0, 0, 3, 2, 1, 8, 2, null, colunasZeradas: ['metas', 'demandas']);
        self::assertSame([], DiagnosticoDaPessoa::comparacoes($zerada));
        self::assertStringNotContainsString('carga recebida', implode(' ', DiagnosticoDaPessoa::motivos($zerada, 6, 'Ana')));
    }

    #[TestDox('Com Zerar em metas, ativas, concluídas ou taxa, a frase-resumo (X% concluído) não sai')]
    public function testZerarOmiteResumo(): void
    {
        foreach (['metas', 'metas_ativas', 'metas_concluidas', 'taxa_conclusao'] as $coluna) {
            $motivos = DiagnosticoDaPessoa::motivos($this->linha(zeradas: [$coluna]), 6, 'Ana');
            self::assertStringNotContainsString('concluído', implode(' ', $motivos), $coluna);
        }
    }

    #[TestDox('Anterior zero não gera percentual inventado')]
    public function testAnteriorZeroSemVariacao(): void
    {
        self::assertNull((new ComparacaoDaPessoaOutput('recebidas', 5, 0))->variacao());
    }

    #[TestDox('Os dados considerados listam cada número, e "sem dado" quando não há base')]
    public function testDados(): void
    {
        $d = DiagnosticoDaPessoa::dados([new MetricaDaPessoaOutput('recebidas', 10), new MetricaDaPessoaOutput('taxa', null, true), new MetricaDaPessoaOutput('concluidas', 6)]);

        self::assertSame(['Recebidas (metas criadas no período): 10', 'Taxa de conclusão (concluídas ÷ recebidas): sem dado', 'Concluídas (das criadas no período): 6'], $d);
    }

    #[TestDox('Os limites declaram o que não é registrado, e o aviso de período só aparece sem período completo')]
    public function testLimites(): void
    {
        self::assertCount(3, DiagnosticoDaPessoa::limites(true));
        self::assertCount(4, DiagnosticoDaPessoa::limites(false));
    }
}
