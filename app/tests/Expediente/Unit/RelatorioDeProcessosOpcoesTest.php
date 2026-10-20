<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use App\Expediente\DTO\RelatorioDeProcessosOpcoes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

#[CoversClass(RelatorioDeProcessosOpcoes::class)]
final class RelatorioDeProcessosOpcoesTest extends TestCase
{
    #[TestDox('Padrão do spPDF: cabeçalho ligado e 10,5 pt')]
    public function testPadrao(): void
    {
        $o = RelatorioDeProcessosOpcoes::daQuery(new InputBag());

        self::assertTrue($o->cabecalho);
        self::assertSame('10,5 pt', $o->fonteRotulo());
        self::assertSame('10.5', $o->fonteCss());
    }

    /** @return iterable<string, array{string, string}> */
    public static function fontes(): iterable
    {
        yield 'abaixo do mínimo' => ['5', '9'];
        yield 'acima do máximo' => ['30', '16'];
        yield 'passo de meio ponto' => ['12,3', '12.5'];
        yield 'texto' => ['abc', '10.5'];
    }

    #[DataProvider('fontes')]
    #[TestDox('Tamanho do texto fica entre 9 e 16 pt, em passos de 0,5')]
    public function testFonte(string $pedida, string $esperada): void
    {
        self::assertSame($esperada, RelatorioDeProcessosOpcoes::daQuery(new InputBag(['fonte' => $pedida]))->fonteCss());
    }

    #[TestDox('cabecalho=0 desliga o cabeçalho')]
    public function testSemCabecalho(): void
    {
        self::assertFalse(RelatorioDeProcessosOpcoes::daQuery(new InputBag(['cabecalho' => '0']))->cabecalho);
    }
}
