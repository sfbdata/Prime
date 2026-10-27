<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\DTO\ComparacaoDaPessoaOutput;
use App\Dashboard\DTO\LeituraDaPessoaOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\Inteligencia\AnaliseDaPessoa;
use App\Dashboard\Inteligencia\SinaisDaPessoa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnaliseDaPessoa::class)]
#[CoversClass(SinaisDaPessoa::class)]
#[Group('dashboard')]
final class AnaliseDaPessoaTest extends TestCase
{
    /** @param list<string> $zeradas */
    private function linha(int $metas = 10, int $ativas = 4, int $vencidas = 0, ?int $metasAnt = 10, array $zeradas = [], ?int $revisao = null): LinhaAdvogadoDashboardOutput
    {
        return new LinhaAdvogadoDashboardOutput(
            userId: 5,
            nomeAdvogado: 'Ana Souza',
            cargoNome: null,
            fotoUrl: null,
            totalMetas: $metas,
            metasAtivas: $ativas,
            metasVencidas: $vencidas,
            prazosProximos: 0,
            totalDemandas: 0,
            demandasAtivas: 0,
            pastasCriadas: 0,
            totalMetasAnterior: $metasAnt,
            extras: $revisao === null ? [] : ['metas_revisao' => $revisao],
            colunasZeradas: $zeradas,
        );
    }

    private function conclusoes(int $atual, int $anterior): ComparacaoDaPessoaOutput
    {
        return new ComparacaoDaPessoaOutput('concluidas', $atual, $anterior, ComparacaoDaPessoaOutput::SOBE_BOM);
    }

    /** @return list<string> */
    private function tipos(?\App\Dashboard\DTO\AnaliseDaPessoaOutput $a): array
    {
        return array_map(static fn (LeituraDaPessoaOutput $l): string => $l->tipo, $a?->leituras ?? []);
    }

    #[TestDox('Queda de conclusões com a carga que subiu é Correlação (coincide, sem afirmar causa) e o fator é a demanda')]
    public function testCorrelacao(): void
    {
        $a = AnaliseDaPessoa::montar($this->linha(metas: 13, metasAnt: 10), $this->conclusoes(6, 10), 4.0);

        self::assertSame([LeituraDaPessoaOutput::CORRELACAO], $this->tipos($a));
        self::assertSame('Conclusões −40% frente ao período anterior, mas a carga recebida subiu +30%. A redução coincide com o aumento da demanda.', $a->leituras[0]->texto);
        self::assertSame(['Aumento da demanda recebida (+30% frente ao período anterior).'], $a->fatores);
        self::assertNotNull($a->plano);
        self::assertSame('Aumento da demanda recebida (+30% frente ao período anterior).', $a->plano['causa']);
        self::assertSame('Conclusões de volta ao nível do período anterior', $a->plano['sucesso']);
    }

    #[TestDox('Queda sem aumento de carga é Observado, e sem comparação honesta de conclusões nenhuma regra de queda dispara')]
    public function testQuedaIsoladaESemBase(): void
    {
        $a = AnaliseDaPessoa::montar($this->linha(), $this->conclusoes(8, 10), 4.0);
        self::assertSame([LeituraDaPessoaOutput::OBSERVADO], $this->tipos($a));
        self::assertSame('Conclusões −20% frente ao próprio período anterior, com carga recebida 0%.', $a->leituras[0]->texto);

        $sem = AnaliseDaPessoa::montar($this->linha(), null, 4.0);
        self::assertSame([], $this->tipos($sem));
        self::assertNull($sem->plano);
        self::assertSame('sem_base', $sem->matriz[1]->estado);
        self::assertSame('sem_base', $sem->matriz[5]->estado);
    }

    #[TestDox('Atrasos: só o limiar absoluto de 4 vencidas, sem razão que misture recortes; plano com sugestão ao gestor')]
    public function testAtrasos(): void
    {
        $a = AnaliseDaPessoa::montar($this->linha(ativas: 2, vencidas: 4), null, 2.0);
        self::assertSame('4 metas com o prazo já vencido.', $a->leituras[0]->texto);
        self::assertSame(['Com 4 metas vencidas, os prazos podem exigir acompanhamento adicional.'], $a->impactos);
        self::assertSame('Vale priorizar as 4 vencidas antes de novas metas.', $a->plano['acao']);
        self::assertSame('48 horas', $a->plano['revisao']);
        self::assertSame('Metas vencidas reduzidas a zero', $a->plano['sucesso']);
        self::assertSame('atencao', $a->matriz[2]->estado);

        $tres = AnaliseDaPessoa::montar($this->linha(ativas: 3, vencidas: 3), null, 3.0);
        self::assertSame([], $this->tipos($tres), '3 vencidas de 3 ativas não dispara: não há proporção');
        self::assertSame('normal', $tres->matriz[2]->estado);
        self::assertDoesNotMatchRegularExpression('/%\s+da fila/u', $a->leituras[0]->texto);
    }

    #[TestDox('Matriz: queda de conclusões é rótulo do volume em âmbar (nunca vermelho); média zero da equipe é Sem base')]
    public function testQuedaNeutraEMediaZero(): void
    {
        $m = AnaliseDaPessoa::montar($this->linha(), $this->conclusoes(8, 10), 0.0)->matriz;
        self::assertSame(['queda', 'atencao'], [$m[5]->estado, $m[5]->tom]);
        self::assertSame('sem_base', $m[6]->estado);
        foreach ($m as $x) {
            self::assertNotSame('ruim', $x->tom);
        }
    }

    #[TestDox('Fila 1,5 vez a média da equipe é sobrecarga; com menos de 3 ativas é efeito de equipe pequena e não dispara')]
    public function testSobrecargaEPiso(): void
    {
        $a = AnaliseDaPessoa::montar($this->linha(ativas: 9), null, 4.0);
        self::assertSame('9 metas ativas, 225% da média da equipe (4,0). Volume não é sinônimo de produtividade.', $a->leituras[0]->texto);
        self::assertStringContainsString('cerca de 3 metas', $a->plano['acao']);
        self::assertSame(['Pode haver concentração de tarefas nesta pessoa.'], $a->impactos);
        self::assertSame('elevada', $a->matriz[6]->estado);

        $pequena = AnaliseDaPessoa::montar($this->linha(ativas: 2), null, 0.5);
        self::assertSame([], $this->tipos($pequena));
        self::assertSame('normal', $pequena->matriz[6]->estado, 'o piso vale para a matriz também');
    }

    #[TestDox('Capacidade aparentemente livre é Hipótese e manda conferir antes de concluir; com vencida não há hipótese')]
    public function testHipotese(): void
    {
        $a = AnaliseDaPessoa::montar($this->linha(ativas: 1), null, 4.0);
        self::assertSame([LeituraDaPessoaOutput::HIPOTESE], $this->tipos($a));
        self::assertStringContainsString('Antes de concluir, conferir', $a->leituras[0]->texto);
        self::assertSame('baixa', $a->matriz[6]->estado);

        self::assertSame([], $this->tipos(AnaliseDaPessoa::montar($this->linha(ativas: 1, vencidas: 1), null, 4.0)), 'com vencida não há hipótese de capacidade livre');
    }

    #[TestDox('Conclusões em alta com prazos em dia é Observado e não gera plano; Zerar tira da regra o número zerado')]
    public function testEvolucaoEZerar(): void
    {
        $a = AnaliseDaPessoa::montar($this->linha(), $this->conclusoes(12, 10), 4.0);
        self::assertSame(['Conclusões +20% frente ao período anterior, com prazos em dia.'], array_map(static fn (LeituraDaPessoaOutput $l): string => $l->texto, $a->leituras));
        self::assertNull($a->plano);
        self::assertSame('melhorando', $a->matriz[5]->estado);

        $z = AnaliseDaPessoa::montar($this->linha(ativas: 9, vencidas: 5, metas: 13, zeradas: ['metas_ativas', 'metas_vencidas', 'metas']), $this->conclusoes(6, 10), 4.0);
        self::assertSame([LeituraDaPessoaOutput::OBSERVADO], $this->tipos($z), 'só a queda: sem carga, sem vencidas e sem fila');
        self::assertSame(['sem_base', 'sem_base', 'sem_base'], [$z->matriz[0]->estado, $z->matriz[2]->estado, $z->matriz[6]->estado]);
    }

    #[TestDox('Metas em revisão viram fator Observado só quando a coluna existe e não está zerada')]
    public function testFatorDeRevisao(): void
    {
        $a = AnaliseDaPessoa::montar($this->linha(vencidas: 5, ativas: 5, revisao: 2), null, 5.0);
        self::assertSame(['2 metas criadas no período aguardando a revisão do gestor.'], $a->fatores);
        self::assertSame($a->fatores[0], $a->plano['causa']);

        self::assertSame([], AnaliseDaPessoa::montar($this->linha(vencidas: 5, ativas: 5, revisao: 2, zeradas: ['metas_revisao']), null, 5.0)->fatores);
        self::assertSame('Os números não indicam a causa. Vale conversar com a pessoa.', AnaliseDaPessoa::montar($this->linha(vencidas: 5, ativas: 5), null, 5.0)->plano['causa']);
    }

    #[TestDox('Sem meta e sem fila não há análise; Complexidade e Retrabalho ficam "Sem registro"')]
    public function testSemDadosEMatriz(): void
    {
        self::assertNull(AnaliseDaPessoa::montar($this->linha(0, 0, 0, 0), null, 4.0));

        $m = AnaliseDaPessoa::montar($this->linha(), $this->conclusoes(10, 10), 4.0)->matriz;
        self::assertSame(['volume_recebido', 'volume_concluido', 'backlog', 'complexidade', 'retrabalho', 'evolucao', 'capacidade'], array_map(static fn ($x): string => $x->chave, $m));
        self::assertSame(['sem_registro', 'sem_registro'], [$m[3]->estado, $m[4]->estado]);
    }

    #[TestDox('Nenhum texto gerado tem travessão nem fala em nota, punição ou decisão automática')]
    public function testLinguagemDeApoio(): void
    {
        $a = AnaliseDaPessoa::montar($this->linha(metas: 13, ativas: 9, vencidas: 5), $this->conclusoes(6, 10), 4.0);
        $todos = implode(' ', [...array_map(static fn (LeituraDaPessoaOutput $l): string => $l->texto, $a->leituras), ...$a->fatores, ...$a->impactos, ...array_values($a->plano ?? [])]);

        self::assertDoesNotMatchRegularExpression('/[—–]/u', $todos);
        self::assertDoesNotMatchRegularExpression('/\b(nota|punir|punição|demitir|penaliz)/iu', $todos);
    }
}
