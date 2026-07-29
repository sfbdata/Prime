<?php

declare(strict_types=1);

namespace App\Tests\AtualizacaoMonetaria\Unit;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\AtualizacaoMonetaria\Enum\SerieIndice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndiceMonetario::class)]
final class IndiceMonetarioTest extends TestCase
{
    private static function indice(string $variacao = '0.14', string $competencia = '2026-06-01'): IndiceMonetario
    {
        return new IndiceMonetario(
            SerieIndice::INPC,
            new \DateTimeImmutable($competencia),
            $variacao,
            SerieIndice::INPC->fonte(),
            new \DateTimeImmutable('2026-07-29 10:00:00'),
        );
    }

    #[Test]
    #[DataProvider('provedorCanonizacao')]
    public function guardaAVariacaoNaFormaDeNumeric12x6(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, self::indice($entrada)->getVariacaoPct());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provedorCanonizacao(): iterable
    {
        yield 'duas casas viram seis' => ['0.14', '0.140000'];
        yield 'seis casas ficam' => ['0.450641', '0.450641'];
        yield 'inteiro ganha as casas' => ['41', '41.000000'];
        yield 'ponto sem casas' => ['41.', '41.000000'];
        yield 'zeros à esquerda somem' => ['041.32', '41.320000'];
        yield 'deflação mantém o sinal' => ['-0.23', '-0.230000'];
        yield 'zero negativo vira zero' => ['-0.000000', '0.000000'];
        yield 'sinal de mais some' => ['+1.5', '1.500000'];
        yield 'espaço em volta é aparado' => [' 0.14 ', '0.140000'];
    }

    /**
     * Sete casas não cabem em numeric(12,6): arredondar por conta própria esconderia a perda, e o
     * valor gravado deixaria de ser o publicado.
     */
    #[Test]
    #[DataProvider('provedorVariacaoInvalida')]
    public function recusaVariacaoQueAColunaNaoGuardaria(string $variacao): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::indice($variacao);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provedorVariacaoInvalida(): iterable
    {
        yield 'sete casas decimais' => ['0.1234567'];
        yield 'parte inteira longa demais' => ['1234567.0'];
        yield 'notação científica' => ['1.4e-1'];
        yield 'texto' => ['indisponível'];
        yield 'vazio' => [''];
        yield 'só o ponto' => ['.'];
    }

    #[Test]
    public function competenciaEhSempreODia1AMeiaNoite(): void
    {
        $indice = self::indice(competencia: '2026-06-01');

        self::assertSame('2026-06-01 00:00:00', $indice->getCompetencia()->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-01', $indice->chaveCompetencia());
    }

    #[Test]
    public function recusaCompetenciaQueNaoSejaODia1(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dia 1/');

        self::indice(competencia: '2026-06-15');
    }

    #[Test]
    public function reimportarOMesmoValorNaoContaComoRevisao(): void
    {
        $indice = self::indice('0.14');

        // '0.14' do BCB × '0.140000' que o Postgres devolve: mesmo índice.
        $mudou = $indice->atualizarVariacao('0.14', new \DateTimeImmutable('2026-08-01 10:00:00'));

        self::assertFalse($mudou, 'valor igual não pode ser reportado como revisão do IBGE');
        self::assertSame('0.140000', $indice->getVariacaoPct());
        self::assertSame(
            '2026-07-29 10:00:00',
            $indice->getImportadoEm()->format('Y-m-d H:i:s'),
            'sem mudança de valor o carimbo fica: é o que faz a reimportação mensal ser no-op',
        );
    }

    #[Test]
    public function valorDiferenteContaComoRevisaoEAtualizaOCarimbo(): void
    {
        $indice = self::indice('0.14');

        $mudou = $indice->atualizarVariacao('0.19', new \DateTimeImmutable('2026-08-01 10:00:00'));

        self::assertTrue($mudou);
        self::assertSame('0.190000', $indice->getVariacaoPct());
        self::assertSame('2026-08-01 10:00:00', $indice->getImportadoEm()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function fonteRegistraASerieDeOrigem(): void
    {
        self::assertSame('BCB/SGS/188', self::indice()->getFonte());
        self::assertSame('BCB/SGS/433', SerieIndice::IPCA->fonte());
        self::assertSame('BCB/SGS/29543', SerieIndice::TAXA_LEGAL->fonte());
    }
}
