<?php

declare(strict_types=1);

namespace App\Tests\AtualizacaoMonetaria\Unit;

use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Service\TabelaIndices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TabelaIndices::class)]
final class TabelaIndicesTest extends TestCase
{
    private static function tabela(): TabelaIndices
    {
        return new TabelaIndices([
            'INPC' => [
                '2020-03-01' => '0.180000',
                '2020-01-01' => '0.190000',
                '2020-02-01' => '0.170000',
            ],
            'TAXA_LEGAL' => [
                '2024-08-01' => '0.098765',
            ],
        ]);
    }

    #[Test]
    public function devolveAVariacaoDaCompetencia(): void
    {
        $tabela = self::tabela();

        self::assertSame('0.190000', $tabela->variacao(SerieIndice::INPC, new \DateTimeImmutable('2020-01-01')));
        self::assertSame('0.098765', $tabela->variacao(SerieIndice::TAXA_LEGAL, new \DateTimeImmutable('2024-08-01')));
    }

    /**
     * O motor pergunta pela competência do mês da data do valor; o dia não importa. Sem isso, um
     * valor de 15/01/2020 não acharia o índice de 01/2020 e a correção sairia zerada em silêncio.
     */
    #[Test]
    public function qualquerDiaDoMesResolveParaACompetencia(): void
    {
        self::assertSame(
            '0.190000',
            self::tabela()->variacao(SerieIndice::INPC, new \DateTimeImmutable('2020-01-31 23:59:59')),
        );
    }

    #[Test]
    public function competenciaAusenteDevolveNullEmVezDeInventar(): void
    {
        $tabela = self::tabela();

        self::assertNull($tabela->variacao(SerieIndice::INPC, new \DateTimeImmutable('2026-07-01')));
        self::assertNull($tabela->variacao(SerieIndice::IPCA, new \DateTimeImmutable('2020-01-01')));
        self::assertFalse($tabela->temCompetencia(SerieIndice::INPC, new \DateTimeImmutable('2026-07-01')));
        self::assertTrue($tabela->temCompetencia(SerieIndice::INPC, new \DateTimeImmutable('2020-01-01')));
    }

    /**
     * É a guarda do índice não publicado (spec §8) e ela é POR SÉRIE: medido em 29/07/2026, a taxa
     * legal já tinha 07/2026 enquanto o INPC parava em 06/2026.
     */
    #[Test]
    public function ultimaCompetenciaEhPorSerie(): void
    {
        $tabela = self::tabela();

        self::assertSame('2020-03-01', $tabela->ultimaCompetencia(SerieIndice::INPC)?->format('Y-m-d'));
        self::assertSame('2024-08-01', $tabela->ultimaCompetencia(SerieIndice::TAXA_LEGAL)?->format('Y-m-d'));
        self::assertNull($tabela->ultimaCompetencia(SerieIndice::IPCA));
    }

    #[Test]
    public function primeiraCompetenciaEUltimaSaemOrdenadasAindaQueAEntradaVenhaBagunçada(): void
    {
        $tabela = self::tabela();

        self::assertSame('2020-01-01', $tabela->primeiraCompetencia(SerieIndice::INPC)?->format('Y-m-d'));
        self::assertSame(
            ['2020-01-01', '2020-02-01', '2020-03-01'],
            $tabela->competencias(SerieIndice::INPC),
        );
    }

    #[Test]
    public function contaAsCompetenciasDeCadaSerie(): void
    {
        $tabela = self::tabela();

        self::assertSame(3, $tabela->quantidade(SerieIndice::INPC));
        self::assertSame(1, $tabela->quantidade(SerieIndice::TAXA_LEGAL));
        self::assertSame(0, $tabela->quantidade(SerieIndice::IPCA));
    }

    #[Test]
    public function tabelaSemNenhumaSerieSeDeclaraVazia(): void
    {
        self::assertTrue((new TabelaIndices())->vazia());
        self::assertTrue((new TabelaIndices(['INPC' => []]))->vazia());
        self::assertFalse(self::tabela()->vazia());
    }

    #[Test]
    public function serieDesconhecidaNaoEntraNaTabela(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TabelaIndices(['IGPM' => ['2020-01-01' => '0.500000']]);
    }
}
