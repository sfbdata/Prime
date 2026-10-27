<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\DTO\MetaDePastasDoMesOutput;
use App\Dashboard\Inteligencia\EquipeDoRitmo;
use App\Dashboard\Inteligencia\LeituraDoRitmo;
use App\Dashboard\Inteligencia\MontarPlanosDeCrescimento;
use App\Dashboard\Inteligencia\NivelDeCrescimento;
use App\Dashboard\Inteligencia\RitmoDaMeta;
use App\Dashboard\Inteligencia\ViabilidadeDoPlano;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Plano "Aumentar o nível": fórmulas fixas sobre a meta de pastas do mês (mês de 20 dias úteis).
 */
#[CoversClass(MontarPlanosDeCrescimento::class)]
#[CoversClass(NivelDeCrescimento::class)]
#[CoversClass(ViabilidadeDoPlano::class)]
#[Group('dashboard')]
final class MontarPlanosDeCrescimentoTest extends TestCase
{
    private function ritmoDaMeta(int $meta, int $realizado, int $decorridos): RitmoDaMeta
    {
        $r = RitmoDaMeta::de(new MetaDePastasDoMesOutput($meta, '2026-10', '2026-10', $realizado, 20, $decorridos, true));
        self::assertNotNull($r);

        return $r;
    }

    private function leitura(int $vencidas = 0, int $prazos = 0): LeituraDoRitmo
    {
        return new LeituraDoRitmo(null, 0, 0, $vencidas, $prazos, 0, null, null, new EquipeDoRitmo(0.0, null, 0.0, null, 0), null, [], [], [], false, []);
    }

    private function linha(string $nome, int $pastas): LinhaAdvogadoDashboardOutput
    {
        return new LinhaAdvogadoDashboardOutput(crc32($nome), $nome, null, null, 0, 0, 0, 0, 0, 0, $pastas);
    }

    /** @return iterable<string, array{NivelDeCrescimento, int, int}> */
    public static function niveis(): iterable
    {
        yield 'manter fica igual'           => [NivelDeCrescimento::Manter, 40, 40];
        yield '+10% de 40'                  => [NivelDeCrescimento::Mais10, 40, 44];
        yield '+20% de 33 arredonda p/ cima' => [NivelDeCrescimento::Mais20, 33, 40];
        yield '+35% de 40'                  => [NivelDeCrescimento::Mais35, 40, 54];
        yield 'teto de 9999'                => [NivelDeCrescimento::Mais35, 9000, 9999];
    }

    #[DataProvider('niveis')]
    #[TestDox('Nova meta: nível sobre a meta atual, arredondada para cima e no teto')]
    public function testNovaMeta(NivelDeCrescimento $nivel, int $base, int $esperada): void
    {
        self::assertSame($esperada, $nivel->novaMeta($base));
    }

    #[TestDox('Faltam, dias e ritmo: meta 100, 40 criadas, dia útil 10 de 20, nível +20% = 120 (faltam 80 em 10 dias = 8 por dia)')]
    public function testFormulaBase(): void
    {
        $planos = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(100, 40, 10), $this->leitura(), []);

        self::assertCount(4, $planos);
        $p = $planos[NivelDeCrescimento::Mais20->value];
        self::assertSame(120, $p->meta);
        self::assertSame(100, $p->metaAtual);
        self::assertSame(80, $p->faltam);
        self::assertSame(10, $p->dias);
        self::assertSame(8.0, $p->ritmo);
        self::assertSame(4.0, $p->ritmoAtual);
        self::assertSame(20, $p->ganhoReal());
        self::assertTrue($p->aplicavel());
        self::assertFalse($planos[NivelDeCrescimento::Manter->value]->aplicavel(), 'manter não grava a mesma meta');
        self::assertTrue($planos[NivelDeCrescimento::Mais10->value]->padrao());
    }

    #[TestDox('Viabilidade pelo esforço (ritmo exigido / (ritmo atual x 1,25)): viável, desafiador, arriscado, fora e sem base')]
    public function testViabilidade(): void
    {
        $montar = new MontarPlanosDeCrescimento();
        // ritmo atual 10/dia (100 em 10 dias): teto 12,5. Meta 200: faltam 100 em 10 = 10/dia, esforço 0,8.
        $viavel = $montar->montar($this->ritmoDaMeta(200, 100, 10), $this->leitura(), [])[0];
        self::assertSame(ViabilidadeDoPlano::Viavel, $viavel->viabilidade);
        // Meta 225: faltam 125 = 12,5/dia, esforço 1,0.
        self::assertSame(ViabilidadeDoPlano::Desafiador, $montar->montar($this->ritmoDaMeta(225, 100, 10), $this->leitura(), [])[0]->viabilidade);
        // Meta 250: faltam 150 = 15/dia, esforço 1,2.
        self::assertSame(ViabilidadeDoPlano::Arriscado, $montar->montar($this->ritmoDaMeta(250, 100, 10), $this->leitura(), [])[0]->viabilidade);
        // Meta 400: faltam 300 = 30/dia, esforço 2,4.
        self::assertSame(ViabilidadeDoPlano::ForaDaCapacidade, $montar->montar($this->ritmoDaMeta(400, 100, 10), $this->leitura(), [])[0]->viabilidade);
        // Nenhuma pasta criada: sem ritmo atual para comparar, não se inventa teto.
        self::assertSame(ViabilidadeDoPlano::SemBase, $montar->montar($this->ritmoDaMeta(100, 0, 10), $this->leitura(), [])[0]->viabilidade);
    }

    #[TestDox('Nível já alcançado: nada falta, é viável, sem pessoas e o marco final é a nova meta')]
    public function testJaAlcancado(): void
    {
        $p = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(100, 150, 10), $this->leitura(), [$this->linha('Ana Souza', 150)])[0];

        self::assertTrue($p->jaAlcancado());
        self::assertSame(0, $p->faltam);
        self::assertSame(ViabilidadeDoPlano::Viavel, $p->viabilidade);
        self::assertSame([], $p->pessoas);
        self::assertSame(100, $p->marcos[array_key_last($p->marcos)]->alvo, 'Fim = nova meta mesmo sem faltar nada');
    }

    #[TestDox('Meta por colaborador: proporcional às pastas já criadas, soma fecha em "faltam", quem não criou fica fora')]
    public function testPessoas(): void
    {
        $linhas = [$this->linha('Ana Souza', 30), $this->linha('Bruno Lima', 10), $this->linha('Caio Dias', 0)];

        $p = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(100, 40, 10), $this->leitura(), $linhas)[NivelDeCrescimento::Mais20->value];

        self::assertSame(['Ana Souza', 'Bruno Lima'], array_map(static fn ($x) => $x->nome, $p->pessoas));
        self::assertSame([60, 20], array_map(static fn ($x) => $x->extra, $p->pessoas));
        self::assertSame(80, array_sum(array_map(static fn ($x) => $x->extra, $p->pessoas)));
        self::assertSame([100, 33], array_map(static fn ($x) => $x->pctBarra, $p->pessoas));
    }

    #[TestDox('Maior resto: 3 pessoas iguais dividindo 10 somam 10')]
    public function testMaiorResto(): void
    {
        $linhas = [$this->linha('A', 5), $this->linha('B', 5), $this->linha('C', 5)];

        $p = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(100, 90, 10), $this->leitura(), $linhas)[NivelDeCrescimento::Mais10->value];

        self::assertSame(20, $p->faltam);
        self::assertSame(20, array_sum(array_map(static fn ($x) => $x->extra, $p->pessoas)));
    }

    #[TestDox('Sem pasta criada por ninguém, não há divisão por pessoa (nada inventado)')]
    public function testSemCapacidadeNaoDivide(): void
    {
        $p = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(100, 0, 10), $this->leitura(), [$this->linha('Ana', 0)])[1];

        self::assertSame([], $p->pessoas);
    }

    #[TestDox('Marcos por semana útil (5 dias): 10 dias restantes dão 2 marcos e o último, "Fim", é a nova meta')]
    public function testMarcos(): void
    {
        $p = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(100, 40, 10), $this->leitura(), [])[NivelDeCrescimento::Mais20->value];

        self::assertCount(2, $p->marcos);
        self::assertSame([80, 120], array_map(static fn ($m) => $m->alvo, $p->marcos));
        self::assertSame([false, true], array_map(static fn ($m) => $m->fim, $p->marcos));
    }

    #[TestDox('Último dia útil: um marco só, "Fim", e o ritmo é tudo o que falta')]
    public function testUltimoDia(): void
    {
        $p = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(100, 90, 20), $this->leitura(), [])[NivelDeCrescimento::Mais20->value];

        self::assertSame(1, $p->dias);
        self::assertSame(30.0, $p->ritmo);
        self::assertCount(1, $p->marcos);
        self::assertTrue($p->marcos[0]->fim);
        self::assertSame(120, $p->marcos[0]->alvo);
    }

    #[TestDox('Alavancas: vencidas, prazos, concentrar nas duas maiores e nível abaixo quando passa da capacidade')]
    public function testAlavancas(): void
    {
        $linhas = [$this->linha('Ana Souza', 30), $this->linha('Bruno Lima', 10)];

        $p = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(400, 100, 10), $this->leitura(3, 2), $linhas)[0];

        self::assertSame(['vencidas', 'prazos', 'concentrar', 'nivel_abaixo'], array_map(static fn ($a) => $a->chave, $p->alavancas));
        self::assertSame(['n' => 3], $p->alavancas[0]->parametros);
        self::assertSame(['nomes' => 'Ana e Bruno'], $p->alavancas[2]->parametros);

        $sem = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(200, 100, 10), $this->leitura(), [$this->linha('Ana', 5)])[0];
        self::assertSame([], $sem->alavancas);
    }

    #[TestDox('Homônimos são pessoas distintas (peso por id) e quem fica com cota zero não aparece')]
    public function testHomonimosECotaZero(): void
    {
        $a = new LinhaAdvogadoDashboardOutput(1, 'Ana Souza', null, null, 0, 0, 0, 0, 0, 0, 100);
        $b = new LinhaAdvogadoDashboardOutput(2, 'Ana Souza', null, null, 0, 0, 0, 0, 0, 0, 100);
        $c = new LinhaAdvogadoDashboardOutput(3, 'Zé Pouco', null, null, 0, 0, 0, 0, 0, 0, 1);

        $p = (new MontarPlanosDeCrescimento())->montar($this->ritmoDaMeta(100, 90, 10), $this->leitura(), [$a, $b, $c])[NivelDeCrescimento::Mais10->value];

        self::assertSame(20, $p->faltam);
        self::assertSame(['Ana Souza', 'Ana Souza'], array_map(static fn ($x) => $x->nome, $p->pessoas));
        self::assertSame([10, 10], array_map(static fn ($x) => $x->extra, $p->pessoas));
    }
}
