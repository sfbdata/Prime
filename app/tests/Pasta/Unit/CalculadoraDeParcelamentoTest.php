<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\CalculadoraDeParcelamento;
use App\Pasta\UseCase\RegistrarParcelamentoDaPastaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A conta do modal "Adicionar pagamento" (dc L.3470-3500). Os valores esperados
 * da tabela Price foram conferidos fora do sistema, com `decimal` de precisão 50
 * e arredondamento meio-para-cima (a conta está no relatório da L16a):
 *
 *   P = 10.000,00 · 1% a.m. · 12x → pmt = 888,48788678… → 888,49; total
 *       arred(pmt × 12) = 10.661,85; última = 10.661,85 − 11 × 888,49 = 888,46
 *   P =  1.000,00 · 2% a.m. · 10x → pmt = 111,32652786… → 111,33; total
 *       1.113,27; última = 111,30
 *   P =  5.000,00 · 1,5% a.m. · 24x → pmt = 249,62050984… → 249,62; total
 *       5.990,89; última = 249,63
 *
 * O 888,49 é o valor de qualquer tabela Price publicada para 10 mil a 1% em 12
 * meses — é a âncora externa da fórmula.
 */
#[CoversClass(CalculadoraDeParcelamento::class)]
final class CalculadoraDeParcelamentoTest extends TestCase
{
    private CalculadoraDeParcelamento $calc;

    protected function setUp(): void
    {
        $this->calc = new CalculadoraDeParcelamento();
    }

    /** @return iterable<string, array{int, int, string, int, int, int}> */
    public static function tabelaPrice(): iterable
    {
        // principal, n, taxa, parcela, última, total (todos em centavos)
        yield '10 mil a 1% em 12x'   => [1000000, 12, '1', 88849, 88846, 1066185];
        yield '1 mil a 2% em 10x'    => [100000, 10, '2', 11133, 11130, 111327];
        yield '5 mil a 1,5% em 24x'  => [500000, 24, '1.5', 24962, 24963, 599089];
        yield '12.860 a 1% em 60x'   => [1286000, 60, '1', 28606, 28628, 1716382];
    }

    #[DataProvider('tabelaPrice')]
    #[TestDox('Price com taxa conhecida: parcela, última e total batem com a tabela conferida ($_dataName)')]
    public function testPriceConferidoContraTabela(int $principal, int $n, string $taxa, int $parcela, int $ultima, int $total): void
    {
        $valores = $this->calc->parcelas($principal, $n, $taxa);

        self::assertCount($n, $valores);
        self::assertSame(array_fill(0, $n - 1, $parcela), array_slice($valores, 0, $n - 1), 'todas iguais menos a última');
        self::assertSame($ultima, $valores[$n - 1], 'a última absorve o resíduo de arredondamento');
        self::assertSame($total, array_sum($valores), 'a soma é exatamente arred(pmt × n)');
    }

    #[TestDox('juros 0 é divisão simples e a soma é EXATAMENTE o principal')]
    public function testSemJurosDivisaoSimples(): void
    {
        self::assertSame([3333, 3333, 3334], $this->calc->parcelas(10000, 3, '0'), '100,00 em 3: a última leva o centavo');
        self::assertSame([50000, 50000], $this->calc->parcelas(100000, 2, '0'));
        self::assertSame([5000, 5000], $this->calc->parcelas(10000, 2), 'sem taxa informada é sem juros');
        self::assertSame([3333, 3333, 3334], $this->calc->parcelas(10000, 3, '0.0000'), '"0.0000" também é sem juros');
    }

    /**
     * 100,00 em 6: P/n = 16,666… arredonda PARA CIMA (16,67), e a última fica
     * MENOR (16,65); em 3, 33,333… arredonda para baixo e a última fica MAIOR.
     * O resíduo pode ir para os dois lados; o que não muda é a soma.
     */
    #[TestDox('o resíduo vai para a última nos dois sentidos — para cima e para baixo')]
    public function testResiduoNaUltimaNosDoisSentidos(): void
    {
        self::assertSame([1667, 1667, 1667, 1667, 1667, 1665], $this->calc->parcelas(10000, 6, '0'), 'última menor');
        self::assertSame([3333, 3333, 3334], $this->calc->parcelas(10000, 3, '0'), 'última maior');
        self::assertSame([1429, 1429, 1429, 1429, 1429, 1429, 1426], $this->calc->parcelas(10000, 7, '0'));
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function combinacoes(): iterable
    {
        foreach ([1, 2, 3, 7, 12, 60, 119, 120] as $n) {
            foreach (['0', '0.5', '1', '2.75', '10'] as $taxa) {
                yield "n={$n} taxa={$taxa}" => [987654, $n, $taxa];
            }
        }
    }

    #[DataProvider('combinacoes')]
    #[TestDox('em qualquer combinação, toda parcela é positiva e só a última difere ($_dataName)')]
    public function testInvariantes(int $principal, int $n, string $taxa): void
    {
        $valores = $this->calc->parcelas($principal, $n, $taxa);

        self::assertCount($n, $valores);
        foreach ($valores as $v) {
            self::assertGreaterThan(0, $v);
        }
        self::assertCount(1, array_unique(array_slice($valores, 0, max(1, $n - 1))), 'as n−1 primeiras são iguais');

        if ($taxa === '0') {
            self::assertSame($principal, array_sum($valores), 'sem juros a soma é o principal');
        } else {
            self::assertGreaterThan($principal, array_sum($valores), 'com juros paga-se mais que o principal');
        }
    }

    #[TestDox('N = 1: sem juros é o valor inteiro; com juros é P × (1 + i)')]
    public function testUmaParcela(): void
    {
        self::assertSame([123456], $this->calc->parcelas(123456, 1, '0'));
        self::assertSame([100990], $this->calc->parcelas(100000, 1, '0.99'), '1.000,00 a 0,99% em 1x = 1.009,90');
    }

    #[TestDox('valor pequeno demais para o número de parcelas é recusado — nada de parcela de R$ 0,00')]
    public function testPequenoDemaisRecusa(): void
    {
        // 1,00 em 120: P/n = 0,0083 → 0,01 por parcela; a última ficaria negativa.
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->parcelas(100, 120, '0');
    }

    #[TestDox('um centavo em duas parcelas também é recusado (a última zeraria)')]
    public function testUmCentavoEmDuas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->parcelas(1, 2, '0');
    }

    #[TestDox('principal zero ou quantidade zero são recusados')]
    public function testEntradasInvalidas(): void
    {
        foreach ([[0, 1], [-100, 1], [1000, 0]] as [$p, $n]) {
            try {
                $this->calc->parcelas($p, $n, '0');
                self::fail("deveria recusar P={$p} n={$n}");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('o teto de 60 parcelas com juros de 10% a.m. ainda dá conta sem estourar')]
    public function testLimitesDaConta(): void
    {
        // O teto é do caso de uso (o do campo no desenho); a calculadora não o impõe.
        $teto    = RegistrarParcelamentoDaPastaUseCase::MAX_PARCELAS;
        $valores = $this->calc->parcelas(9999999999999, $teto, '10');

        self::assertSame(60, $teto);
        self::assertCount($teto, $valores);
        self::assertGreaterThan(0, $valores[$teto - 1]);
    }

    /** @return list<string> */
    private function datas(string $primeiro, int $n): array
    {
        return array_map(
            static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $this->calc->vencimentos(new \DateTimeImmutable($primeiro), $n),
        );
    }

    #[TestDox('fim de mês: 31/01 → 28/02 → 31/03 → 30/04, ancorado no dia original')]
    public function testFimDeMes(): void
    {
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $this->datas('2026-01-31', 4));
        self::assertSame(['2028-01-31', '2028-02-29', '2028-03-31'], $this->datas('2028-01-31', 3), 'ano bissexto');
        self::assertSame(['2026-01-30', '2026-02-28', '2026-03-30'], $this->datas('2026-01-30', 3));
    }

    #[TestDox('vencimentos atravessam o ano e mantêm o dia; a hora sai zerada')]
    public function testViradaDeAno(): void
    {
        self::assertSame(['2026-11-15', '2026-12-15', '2027-01-15', '2027-02-15'], $this->datas('2026-11-15', 4));

        $ultimo = $this->calc->vencimentos(new \DateTimeImmutable('2026-10-06'), 120)[119];
        self::assertSame('2036-09-06', $ultimo->format('Y-m-d'), '120 meses depois');
        self::assertSame('00:00:00', $ultimo->format('H:i:s'));
    }

    #[TestDox('N = 1 tem um vencimento só, o primeiro')]
    public function testUmVencimento(): void
    {
        self::assertSame(['2026-11-06'], $this->datas('2026-11-06', 1));
    }

    #[TestDox('percentual sobre a base arredonda ao centavo, meio para cima')]
    public function testPercentualDe(): void
    {
        self::assertSame(257200, $this->calc->percentualDe(1286000, '20'), '20% de 12.860,00');
        self::assertSame(167, $this->calc->percentualDe(333, '50'), '50% de 3,33 = 1,665 → 1,67');
        self::assertSame(160750, $this->calc->percentualDe(1286000, '12.5'));
    }
}
