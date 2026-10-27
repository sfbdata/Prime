<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Service\ValorEmReais;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ValorEmReaisTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?int}> */
    public static function valores(): iterable
    {
        yield 'vírgula' => ['19,90', 1990];
        yield 'uma casa' => ['19,9', 1990];
        yield 'milhar e vírgula' => ['1.234,56', 123456];
        yield 'ponto decimal' => ['19.90', 1990];
        yield 'ponto de milhar' => ['1.234', 123400];
        yield 'milhares' => ['1.234.567', 123456700];
        yield 'com R$' => ['R$ 50', 5000];
        yield 'número' => [12.5, 1250];
        yield 'inteiro' => [7, 700];
        yield 'zero' => ['0', null];
        yield 'zero decimal' => ['0,00', null];
        yield 'vazio' => ['', null];
        yield 'letras' => ['abc', null];
        yield 'negativo vira positivo' => ['-5', 500];
        yield 'nulo' => [null, null];
        yield 'lista' => [['1'], null];
    }

    #[DataProvider('valores')]
    #[TestDox('lê o valor digitado em centavos')]
    public function testParaCentavos(mixed $texto, ?int $esperado): void
    {
        self::assertSame($esperado, ValorEmReais::paraCentavos($texto));
    }
}
