<?php

declare(strict_types=1);

namespace App\Tests\AtualizacaoMonetaria\Unit;

use App\AtualizacaoMonetaria\Service\VariacaoPercentual;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A definição da forma canônica de `numeric(12,6)` mora aqui e é usada por três peças (cliente do
 * BCB, entidade e `TabelaIndices`). Se este teste cair, as três mudam de comportamento junto.
 */
#[CoversClass(VariacaoPercentual::class)]
final class VariacaoPercentualTest extends TestCase
{
    #[Test]
    #[DataProvider('provedorCanonico')]
    public function canoniza(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, VariacaoPercentual::canonizar($entrada));
        self::assertTrue(VariacaoPercentual::ehArmazenavel($entrada));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provedorCanonico(): iterable
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
        yield 'limite da parte inteira' => ['999999.999999', '999999.999999'];
        yield 'já canônico é idempotente' => ['0.140000', '0.140000'];
    }

    #[Test]
    #[DataProvider('provedorImpossivel')]
    public function recusaOQueNaoCaberiaEmNumeric12x6(string $entrada): void
    {
        self::assertFalse(
            VariacaoPercentual::ehArmazenavel($entrada),
            'ehArmazenavel tem de concordar com canonizar — é ela que o cliente do BCB consulta',
        );

        $this->expectException(\InvalidArgumentException::class);
        VariacaoPercentual::canonizar($entrada);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provedorImpossivel(): iterable
    {
        yield 'sete casas decimais' => ['0.1234567'];
        yield 'parte inteira longa demais' => ['1234567.0'];
        // is_numeric() aceita estas três — foi por isso que o cliente do BCB as deixava passar até a
        // entidade lançar, no meio da importação (achado A3 da revisão).
        yield 'notação científica' => ['1.4e-1'];
        yield 'sem o zero antes do ponto' => ['.14'];
        yield 'hexadecimal' => ['0x1A'];
        yield 'texto' => ['indisponível'];
        yield 'vazio' => [''];
        yield 'só o ponto' => ['.'];
        yield 'só o sinal' => ['-'];
        yield 'separador brasileiro' => ['0,14'];
    }

    /**
     * A comparação que o importador usa para decidir se o IBGE revisou o índice: os dois lados
     * canonizados, comparados como string. Sem BCMath, sem float.
     */
    #[Test]
    public function formasDiferentesDoMesmoNumeroCanonizamIgual(): void
    {
        self::assertSame(
            VariacaoPercentual::canonizar('0.14'),
            VariacaoPercentual::canonizar('0.140000'),
        );
        self::assertNotSame(
            VariacaoPercentual::canonizar('0.14'),
            VariacaoPercentual::canonizar('0.140001'),
        );
    }
}
