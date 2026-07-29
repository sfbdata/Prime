<?php

declare(strict_types=1);

namespace App\Tests\AtualizacaoMonetaria\Unit;

use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\Tests\AtualizacaoMonetaria\TabelaIndicesDeFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guarda da fixture de índices que o motor da Parte 3 vai usar.
 *
 * Sem isso, uma fixture truncada ou com buraco no meio faria o teste de fidelidade falhar por
 * "diferença de centavos" e mandaria quem estivesse depurando caçar erro na fórmula — quando o
 * problema seria o dado. Aqui o dado é verificado antes.
 */
#[CoversClass(TabelaIndicesDeFixture::class)]
final class FixtureIndicesBcbTest extends TestCase
{
    #[Test]
    public function aFixtureCarregaNaTabelaIndices(): void
    {
        self::assertFileExists(TabelaIndicesDeFixture::ARQUIVO);
        self::assertFalse(TabelaIndicesDeFixture::carregar()->vazia());
    }

    #[Test]
    #[DataProvider('provedorCobertura')]
    public function cadaSerieTemACoberturaMedidaEm29072026(
        SerieIndice $serie,
        int $meses,
        string $primeira,
        string $ultima,
    ): void {
        $tabela = TabelaIndicesDeFixture::carregar();

        self::assertSame($meses, $tabela->quantidade($serie));
        self::assertSame($primeira, $tabela->primeiraCompetencia($serie)?->format('Y-m-d'));
        self::assertSame($ultima, $tabela->ultimaCompetencia($serie)?->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{SerieIndice, int, string, string}>
     */
    public static function provedorCobertura(): iterable
    {
        yield 'INPC' => [SerieIndice::INPC, 390, '1994-01-01', '2026-06-01'];
        yield 'IPCA' => [SerieIndice::IPCA, 390, '1994-01-01', '2026-06-01'];
        // A taxa legal da Lei 14.905/2024 só existe a partir de 08/2024 — e em 29/07/2026 ela já
        // tinha 07/2026 publicado enquanto INPC e IPCA paravam em 06/2026.
        yield 'taxa legal' => [SerieIndice::TAXA_LEGAL, 24, '2024-08-01', '2026-07-01'];
    }

    /**
     * Um mês faltando no meio da série é o defeito mais traiçoeiro possível aqui: a correção sai
     * menor e nada acusa, porque o fator continua sendo um número plausível.
     */
    #[Test]
    #[DataProvider('provedorSeries')]
    public function nenhumaSerieTemBuracoEntreAPrimeiraEAUltimaCompetencia(SerieIndice $serie): void
    {
        $competencias = TabelaIndicesDeFixture::carregar()->competencias($serie);

        $esperada = new \DateTimeImmutable($competencias[0]);
        foreach ($competencias as $competencia) {
            self::assertSame(
                $esperada->format('Y-m-d'),
                $competencia,
                sprintf('buraco na série %s: esperava %s', $serie->value, $esperada->format('Y-m')),
            );
            $esperada = $esperada->modify('+1 month');
        }
    }

    /**
     * @return iterable<string, array{SerieIndice}>
     */
    public static function provedorSeries(): iterable
    {
        foreach (SerieIndice::cases() as $serie) {
            yield $serie->value => [$serie];
        }
    }

    /**
     * Valores conferidos contra o que o BCB devolveu em 29/07/2026. Se um deles mudar, ou o IBGE
     * revisou um índice antigo (e aí os 22 casos de referência precisam ser recapturados), ou a
     * fixture foi regenerada por engano contra dado diferente.
     */
    #[Test]
    #[DataProvider('provedorValoresConhecidos')]
    public function valoresDeReferenciaBatemComOQueOBcbPublicou(
        SerieIndice $serie,
        string $competencia,
        string $esperado,
    ): void {
        self::assertSame(
            $esperado,
            TabelaIndicesDeFixture::carregar()->variacao($serie, new \DateTimeImmutable($competencia)),
        );
    }

    /**
     * @return iterable<string, array{SerieIndice, string, string}>
     */
    public static function provedorValoresConhecidos(): iterable
    {
        yield 'INPC 01/1994 (primeiro mês da série)' => [SerieIndice::INPC, '1994-01-01', '41.320000'];
        yield 'INPC 06/2026 (último publicado)' => [SerieIndice::INPC, '2026-06-01', '0.140000'];
        yield 'IPCA 06/2026' => [SerieIndice::IPCA, '2026-06-01', '0.160000'];
        yield 'taxa legal 07/2026' => [SerieIndice::TAXA_LEGAL, '2026-07-01', '0.706607'];
    }

    /**
     * Toda variação tem de estar na forma canônica de numeric(12,6). O motor compara e acumula
     * essas strings; uma que viesse como '0.14' ou em notação científica quebraria a aritmética
     * decimal em silêncio.
     */
    #[Test]
    public function todaVariacaoEstaNaFormaCanonicaDeNumeric12x6(): void
    {
        foreach (TabelaIndicesDeFixture::variacoes() as $serie => $meses) {
            foreach ($meses as $competencia => $variacao) {
                self::assertMatchesRegularExpression(
                    '/^-?\d{1,6}\.\d{6}$/',
                    $variacao,
                    sprintf('%s %s fora da forma canônica: "%s"', $serie, $competencia, $variacao),
                );
            }
        }
    }
}
