<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\PastaImpressaoOpcoes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

#[CoversClass(PastaImpressaoOpcoes::class)]
final class PastaImpressaoOpcoesTest extends TestCase
{
    /** @param array<string, string> $query */
    private function opcoes(array $query): PastaImpressaoOpcoes
    {
        return PastaImpressaoOpcoes::daQuery(new InputBag($query));
    }

    #[TestDox('Sem query: os padrões do desenho (gmImprimir) — resumo, cabeçalho/dados/autor ligados, financeiro desligado, 11,5 pt')]
    public function testPadroes(): void
    {
        $o = $this->opcoes([]);

        self::assertSame('resumo', $o->secao);
        self::assertTrue($o->cabecalho);
        self::assertTrue($o->dados);
        self::assertTrue($o->autor);
        self::assertFalse($o->financeiro);
        self::assertSame(11.5, $o->fonte);
        self::assertSame('11,5 pt', $o->fonteRotulo());
        self::assertSame('11.5', $o->fonteCss());
    }

    #[TestDox('Cada opção liga e desliga pelo 1/0 da query')]
    public function testLigaDesliga(): void
    {
        $o = $this->opcoes(['cabecalho' => '0', 'dados' => '0', 'autor' => '0', 'financeiro' => '1']);

        self::assertFalse($o->cabecalho);
        self::assertFalse($o->dados);
        self::assertFalse($o->autor);
        self::assertTrue($o->financeiro);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function secoes(): iterable
    {
        yield 'resumo' => ['resumo', 'resumo'];
        yield 'extrato' => ['extrato', 'extrato'];
        yield 'moldura' => ['moldura', 'moldura'];
        yield 'desconhecida vira null (404)' => ['../segredo', null];
    }

    #[DataProvider('secoes')]
    #[TestDox('Seção: $pedida → $esperada')]
    public function testSecao(string $pedida, ?string $esperada): void
    {
        self::assertSame($esperada, $this->opcoes(['secao' => $pedida])->secao);
    }

    /** @return iterable<string, array{string, float, string}> */
    public static function fontes(): iterable
    {
        yield 'inteiro' => ['14', 14.0, '14 pt'];
        yield 'meio ponto' => ['9.5', 9.5, '9,5 pt'];
        yield 'vírgula' => ['12,5', 12.5, '12,5 pt'];
        yield 'arredonda ao passo de 0,5' => ['12.3', 12.5, '12,5 pt'];
        yield 'teto' => ['40', 16.0, '16 pt'];
        yield 'piso' => ['2', 9.0, '9 pt'];
        yield 'lixo vira o padrão' => ['grande', 11.5, '11,5 pt'];
    }

    #[DataProvider('fontes')]
    #[TestDox('Tamanho do texto: "$pedida" → $rotulo')]
    public function testFonte(string $pedida, float $esperada, string $rotulo): void
    {
        $o = $this->opcoes(['fonte' => $pedida]);

        self::assertSame($esperada, $o->fonte);
        self::assertSame($rotulo, $o->fonteRotulo());
    }
}
