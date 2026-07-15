<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\LinhaParcelaGerada;
use App\Cobranca\Enum\Periodicidade;
use App\Cobranca\Exception\ParcelamentoInvalidoException;
use App\Cobranca\Service\GeradorParcelamento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeradorParcelamento::class)]
#[CoversClass(Periodicidade::class)]
#[CoversClass(LinhaParcelaGerada::class)]
final class GeradorParcelamentoTest extends TestCase
{
    private GeradorParcelamento $sut;

    protected function setUp(): void
    {
        $this->sut = new GeradorParcelamento();
    }

    #[Test]
    public function divideEmParcelasIguaisQuandoFechaExato(): void
    {
        $linhas = $this->sut->gerar(90000, 0, 3, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);

        self::assertCount(3, $linhas);
        self::assertSame([30000, 30000, 30000], array_map(static fn (LinhaParcelaGerada $l): int => $l->valor, $linhas));
        self::assertSame('Parcela 1/3', $linhas[0]->descricao);
        self::assertSame('Parcela 3/3', $linhas[2]->descricao);
    }

    #[Test]
    public function sobraDeCentavosCaiNaPrimeiraParcela(): void
    {
        // 100,00 ÷ 3 = 33,34 + 33,33 + 33,33 (D3: primeira absorve a sobra).
        $linhas = $this->sut->gerar(10000, 0, 3, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);

        self::assertSame([3334, 3333, 3333], array_map(static fn (LinhaParcelaGerada $l): int => $l->valor, $linhas));
        self::assertSame(10000, array_sum(array_map(static fn (LinhaParcelaGerada $l): int => $l->valor, $linhas)));
    }

    #[Test]
    public function parcelaUnicaRecebeTodoOTotal(): void
    {
        $linhas = $this->sut->gerar(12345, 0, 1, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);

        self::assertCount(1, $linhas);
        self::assertSame(12345, $linhas[0]->valor);
        self::assertSame('Parcela 1/1', $linhas[0]->descricao);
    }

    #[Test]
    public function entradaEhAbatidaDoTotalAntesDeParcelar(): void
    {
        // Total 100,00, entrada 40,00 → parcela 60,00 em 3 = 20,00 cada.
        $linhas = $this->sut->gerar(10000, 4000, 3, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);

        self::assertSame([2000, 2000, 2000], array_map(static fn (LinhaParcelaGerada $l): int => $l->valor, $linhas));
        // A entrada NÃO aparece nas linhas geradas (é tratada à parte pelo UseCase).
        self::assertSame(6000, array_sum(array_map(static fn (LinhaParcelaGerada $l): int => $l->valor, $linhas)));
    }

    #[Test]
    public function vencimentosMensaisSeguemEmSequencia(): void
    {
        $linhas = $this->sut->gerar(30000, 0, 3, new \DateTimeImmutable('2026-01-15'), Periodicidade::Mensal);

        self::assertSame('2026-01-15', $linhas[0]->vencimento->format('Y-m-d'));
        self::assertSame('2026-02-15', $linhas[1]->vencimento->format('Y-m-d'));
        self::assertSame('2026-03-15', $linhas[2]->vencimento->format('Y-m-d'));
    }

    #[Test]
    public function vencimentoMensalFazClampNoUltimoDiaDoMes(): void
    {
        // 1ª em 31/01: fevereiro não tem dia 31 → clamp em 28; março volta ao dia 31 (deriva da 1ª).
        $linhas = $this->sut->gerar(30000, 0, 3, new \DateTimeImmutable('2026-01-31'), Periodicidade::Mensal);

        self::assertSame('2026-01-31', $linhas[0]->vencimento->format('Y-m-d'));
        self::assertSame('2026-02-28', $linhas[1]->vencimento->format('Y-m-d'));
        self::assertSame('2026-03-31', $linhas[2]->vencimento->format('Y-m-d'));
    }

    #[Test]
    public function vencimentosSemanaisSomam7Dias(): void
    {
        $linhas = $this->sut->gerar(30000, 0, 3, new \DateTimeImmutable('2026-05-10'), Periodicidade::Semanal);

        self::assertSame('2026-05-10', $linhas[0]->vencimento->format('Y-m-d'));
        self::assertSame('2026-05-17', $linhas[1]->vencimento->format('Y-m-d'));
        self::assertSame('2026-05-24', $linhas[2]->vencimento->format('Y-m-d'));
    }

    #[Test]
    public function vencimentosQuinzenaisSomam14Dias(): void
    {
        $linhas = $this->sut->gerar(30000, 0, 3, new \DateTimeImmutable('2026-05-10'), Periodicidade::Quinzenal);

        self::assertSame('2026-05-10', $linhas[0]->vencimento->format('Y-m-d'));
        self::assertSame('2026-05-24', $linhas[1]->vencimento->format('Y-m-d'));
        self::assertSame('2026-06-07', $linhas[2]->vencimento->format('Y-m-d'));
    }

    /**
     * @param LinhaParcelaGerada[] $linhas
     */
    #[Test]
    #[DataProvider('casosQueFecham')]
    public function somaSempreFechaComOTotalParcelado(int $total, int $entrada, int $qtd): void
    {
        $linhas = $this->sut->gerar($total, $entrada, $qtd, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);

        self::assertCount($qtd, $linhas);
        self::assertSame($total - $entrada, array_sum(array_map(static fn (LinhaParcelaGerada $l): int => $l->valor, $linhas)));
        // Toda parcela é positiva.
        foreach ($linhas as $linha) {
            self::assertGreaterThan(0, $linha->valor);
        }
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function casosQueFecham(): iterable
    {
        yield 'dízima 100/3' => [10000, 0, 3];
        yield 'dízima 100/7' => [10000, 0, 7];
        yield 'com entrada quebrada' => [10000, 3333, 3];
        yield '12 parcelas' => [123457, 0, 12];
        yield 'valor mínimo por parcela' => [3, 0, 3];
    }

    #[Test]
    public function rejeitaTotalNaoPositivo(): void
    {
        $this->expectException(ParcelamentoInvalidoException::class);
        $this->sut->gerar(0, 0, 3, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);
    }

    #[Test]
    public function rejeitaEntradaNegativa(): void
    {
        $this->expectException(ParcelamentoInvalidoException::class);
        $this->sut->gerar(10000, -1, 3, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);
    }

    #[Test]
    public function rejeitaQuantidadeMenorQueUm(): void
    {
        $this->expectException(ParcelamentoInvalidoException::class);
        $this->sut->gerar(10000, 0, 0, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);
    }

    #[Test]
    public function rejeitaEntradaQueConsomeTodoOTotal(): void
    {
        $this->expectException(ParcelamentoInvalidoException::class);
        $this->sut->gerar(10000, 10000, 3, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);
    }

    #[Test]
    public function rejeitaTotalBaixoDemaisParaGerarParcelasPositivas(): void
    {
        // 2 centavos a parcelar em 3 parcelas → impossível manter todas positivas.
        $this->expectException(ParcelamentoInvalidoException::class);
        $this->sut->gerar(2, 0, 3, new \DateTimeImmutable('2026-05-10'), Periodicidade::Mensal);
    }
}
