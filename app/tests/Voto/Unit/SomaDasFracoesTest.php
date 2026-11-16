<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Service\SomaDasFracoes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SomaDasFracoes::class)]
final class SomaDasFracoesTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function somas(): iterable
    {
        yield 'fecha 1' => ['1.000000', false];
        yield 'quase 1, dentro da tolerância' => ['0.996000', false];
        yield 'fecha 100' => ['100.000000', false];
        yield 'quase 100, dentro da tolerância' => ['99.600000', false];
        yield 'abaixo de 1' => ['0.900000', true];
        yield 'acima de 1' => ['1.010000', true];
        yield 'no meio do caminho' => ['50.000000', true];
        yield 'acima de 100' => ['100.500000', true];
        yield 'zero' => ['0.000000', true];
    }

    #[DataProvider('somas')]
    #[TestDox('Soma das frações avisa quando não fecha 1 nem 100')]
    public function testAvisa(string $soma, bool $fora): void
    {
        self::assertSame($fora, SomaDasFracoes::foraDoEsperado($soma), $soma);
    }
}
