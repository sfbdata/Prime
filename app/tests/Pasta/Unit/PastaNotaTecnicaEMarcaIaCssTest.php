<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * E3 da frente de fidelidade (`docs/specs/fidelidade-designer-1310.md` §7): o que o pacote do Claude
 * Designer de 13/10 mudou em `02 - EXPEDIENTES 1.2.3.dc.html` além dos cantos.
 *
 * - Nota técnica (dc L1796/1798/1812, iguais em L2638/2640/2654 no teor do Push): fundo #f2f8fc→#f6fafd,
 *   borda #0a5f86→#b9d6e8, raio 3→4px, conector em "L" de 2px #9fc3d8 para 1.5px #c9dfec. O selo
 *   "NOTA TÉCNICA" e o avatar NÃO mudaram — continuam #0a5f86.
 * - Marca do cabeçalho (dc L1246): BLUEJUS 9px/800/.16em #0c7a9c → 10.5px/700/.12em #0a6fa0; Intelligence
 *   13 → 14.5px; o bloco ganha gap de 2px e line-height 1.1.
 *
 * Só o tema claro tem valor no desenho; o escuro segue a convenção do sistema e não é travado aqui.
 */
final class PastaNotaTecnicaEMarcaIaCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/';

    private function css(string $arquivo): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS . $arquivo));
    }

    /** Corpo da PRIMEIRA regra cujo seletor é exatamente `$seletor` (a do tema claro vem antes da do escuro). */
    private function regra(string $arquivo, string $seletor): string
    {
        $ok = preg_match('/(?:^|\})\s*' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/', $this->css($arquivo), $m);
        self::assertSame(1, $ok, 'Falta a regra ' . $seletor . ' em ' . $arquivo);

        return $m[1];
    }

    private function propriedade(string $corpo, string $nome): string
    {
        $ok = preg_match('/(?:^|[;{\s])' . preg_quote($nome, '/') . ':\s*([^;]+);/', $corpo, $m);
        self::assertSame(1, $ok, 'Falta ' . $nome);

        return trim($m[1]);
    }

    #[TestDox('nota técnica no tema claro: fundo #f6fafd, borda #b9d6e8, conector #c9dfec; selo continua #0a5f86')]
    public function testTokensDaNotaNoTemaClaro(): void
    {
        $tokens = $this->regra('nota-tecnica.css', '.ps-page');

        self::assertSame('#f6fafd', $this->propriedade($tokens, '--ps-nota-bg'));
        self::assertSame('#b9d6e8', $this->propriedade($tokens, '--ps-nota-border'));
        self::assertSame('#c9dfec', $this->propriedade($tokens, '--ps-nota-conector'));
        self::assertSame('#0a5f86', $this->propriedade($tokens, '--ps-nota-selo-bg'), 'o selo não mudou no desenho');
    }

    #[TestDox('caixa da nota e caixa do editor com cantos de 4px')]
    public function testCaixasComQuatroPixels(): void
    {
        self::assertSame('4px', $this->propriedade($this->regra('nota-tecnica.css', '.ps-page .ps-nota'), 'border-radius'));
        self::assertSame('4px', $this->propriedade($this->regra('nota-tecnica.css', '.ps-page .ps-nota-editor'), 'border-radius'));
    }

    #[TestDox('conector em "L" com traço de 1.5px')]
    public function testConectorMaisFino(): void
    {
        $conector = $this->regra('nota-tecnica.css', '.ps-page .ps-notas::before');

        self::assertSame('1.5px solid var(--ps-nota-conector)', $this->propriedade($conector, 'border-left'));
        self::assertSame('1.5px solid var(--ps-nota-conector)', $this->propriedade($conector, 'border-bottom'));
        self::assertSame('6px', $this->propriedade($conector, 'border-bottom-left-radius'));
    }

    #[TestDox('marca BLUEJUS Intelligence do cabeçalho: 10.5px/700/.12em #0a6fa0 e Intelligence 14.5px')]
    public function testMarcaDoCabecalho(): void
    {
        $rotulo = $this->regra('pasta-ia-agentes.css', '.ps-cab-ia-rotulo');
        self::assertSame('2px', $this->propriedade($rotulo, 'gap'));
        self::assertSame('1.1', $this->propriedade($rotulo, 'line-height'));

        $marca = $this->regra('pasta-ia-agentes.css', '.ps-cab-ia-marca');
        self::assertSame('10.5px', $this->propriedade($marca, 'font-size'));
        self::assertSame('700', $this->propriedade($marca, 'font-weight'));
        self::assertSame('.12em', $this->propriedade($marca, 'letter-spacing'));
        self::assertSame('var(--ia-marca-cab)', $this->propriedade($marca, 'color'));

        self::assertSame('14.5px', $this->propriedade($this->regra('pasta-ia-agentes.css', '.ps-cab-ia-nome'), 'font-size'));
    }

    #[TestDox('a cor da marca é token próprio (#0a6fa0 no claro); --ia-azul-txt, usado em outros lugares, não muda')]
    public function testTokenDaMarca(): void
    {
        $css = $this->css('pasta-ia-agentes.css');

        self::assertSame(1, preg_match('/--ia-marca-cab:\s*#0a6fa0;/', $css));
        self::assertSame(1, preg_match('/--ia-azul-txt:\s*#0c7a9c;/', $css));
    }
}
