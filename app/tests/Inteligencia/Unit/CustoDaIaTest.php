<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Service\CustoDaIa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Preço de tabela por milhão de tokens (entrada / saída): Haiku 5.5 US$ 0,10 / 0,50 · Sonnet 5.5 US$ 2 / 10 ·
 * Opus 5.5 US$ 4 / 20. Em centavos, arredondado para cima; a região `us` custa 1,1x.
 */
#[CoversClass(CustoDaIa::class)]
final class CustoDaIaTest extends TestCase
{
    #[TestDox('Haiku 5.5 na região global: 10 milhões de entrada + 2 milhões de saída = US$ 1,00 + US$ 1,00 = 200 centavos')]
    public function testHaikuGlobal(): void
    {
        $custo = new CustoDaIa('global');

        self::assertSame(200, $custo->centavosDaAnalise(ModeloDeIa::Haiku55->value, 10_000_000, 2_000_000));
        self::assertSame(0, $custo->centavosDaAnalise(ModeloDeIa::Haiku55->value, null, null), 'sem tokens não custa nada');
    }

    #[TestDox('modelo gravado fora do enum (ou nulo) paga o preço do Opus 5.5, o mais caro')]
    public function testModeloDesconhecidoPagaOMaisCaro(): void
    {
        $custo = new CustoDaIa('');
        $opus = $custo->centavosDaAnalise(ModeloDeIa::Opus55->value, 1_000_000, 1_000_000);

        self::assertSame(2400, $opus, 'US$ 4 + US$ 20');
        self::assertSame($opus, $custo->centavosDaAnalise('claude-fable-1', 1_000_000, 1_000_000));
        self::assertSame($opus, $custo->centavosDaAnalise(null, 1_000_000, 1_000_000));
    }

    #[TestDox('região us multiplica por 1,1 (também com espaço e maiúscula no ambiente)')]
    public function testFatorDaRegiaoUs(): void
    {
        self::assertSame(1000, (new CustoDaIa('global'))->centavosDaAnalise(ModeloDeIa::Sonnet55->value, 0, 1_000_000));
        self::assertSame(1100, (new CustoDaIa('us'))->centavosDaAnalise(ModeloDeIa::Sonnet55->value, 0, 1_000_000));
        self::assertSame(1100, (new CustoDaIa(' US '))->centavosDaAnalise(ModeloDeIa::Sonnet55->value, 0, 1_000_000));
    }

    #[TestDox('arredonda PARA CIMA: um token de Haiku já custa 1 centavo; o consumo agrupado arredonda uma vez só, no fim')]
    public function testArredondaParaCima(): void
    {
        $custo = new CustoDaIa('global');

        self::assertSame(1, $custo->centavosDaAnalise(ModeloDeIa::Haiku55->value, 1, 0));
        self::assertSame(1, $custo->centavosDaAnalise(ModeloDeIa::Haiku55->value, 100_000, 0), 'exato: 1 centavo');
        self::assertSame(2, $custo->centavosDaAnalise(ModeloDeIa::Haiku55->value, 100_001, 0));
        // Duas linhas de meio centavo cada somam 1 centavo, não 2.
        self::assertSame(1, $custo->centavosDoConsumo([
            ['modelo' => ModeloDeIa::Haiku55->value, 'tokensEntrada' => 50_000, 'tokensSaida' => 0],
            ['modelo' => ModeloDeIa::Haiku55->value, 'tokensEntrada' => 50_000, 'tokensSaida' => 0],
        ]));
        self::assertSame(0, $custo->centavosDoConsumo([]));
    }

    #[TestDox('consumo com vários modelos soma cada um pelo próprio preço, com o fator da região')]
    public function testConsumoDeVariosModelos(): void
    {
        $linhas = [
            ['modelo' => ModeloDeIa::Haiku55->value, 'tokensEntrada' => 10_000_000, 'tokensSaida' => 0],
            ['modelo' => ModeloDeIa::Sonnet55->value, 'tokensEntrada' => 1_000_000, 'tokensSaida' => 0],
        ];

        self::assertSame(300, (new CustoDaIa('global'))->centavosDoConsumo($linhas));
        self::assertSame(330, (new CustoDaIa('us'))->centavosDoConsumo($linhas));
    }
}
