<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-023 (docs/specs/engineering-loop/inventario-editor.md): em tela sem hover (toque), os
 * botões da barra crescem para 34×32 — `@media (hover:none){.bjed-b{width:34px;height:32px}}` do
 * desenho (bj-editor.js l.137). No desenho, cor, realce e alinhamento também são `.bjed-b`; o
 * estilo do parágrafo é `.bjed-sel` e a mini barra do botão direito (`.bjed-mt .bjed-b`) tem
 * regra própria mais específica, então nenhum dos dois cresce.
 *
 * Teste de FOLHA (lê o CSS). O tamanho na tela é do smoke num aparelho de toque.
 */
final class EditorRicoToqueTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/editor-rico.css';

    private function css(): string
    {
        return (string) file_get_contents(self::CSS);
    }

    /** O corpo do `@media (hover: none) { … }` (um só no arquivo). */
    private function blocoSemHover(): string
    {
        $css = $this->css();
        self::assertSame(1, substr_count($css, '@media (hover: none)'), 'deve existir exatamente um @media (hover: none)');
        self::assertSame(1, preg_match('/@media \(hover: none\) \{\n(.*?)\n\}/s', $css, $m));

        return $m[1];
    }

    /** Declarações da regra cujo seletor é exatamente `$seletor` dentro do bloco. */
    private function regra(string $bloco, string $seletor): string
    {
        self::assertSame(1, preg_match('/(?:^|\n)\s*' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/', $bloco, $m), "regra {$seletor} não encontrada no @media");

        return $m[1];
    }

    #[TestDox('os botões da barra ficam com 34×32 em tela sem hover')]
    public function testBotoesDaBarraCrescem(): void
    {
        $regra = $this->regra($this->blocoSemHover(), '.editor-rico .ql-snow.ql-toolbar button');

        self::assertStringContainsString('width: 34px;', $regra);
        self::assertStringContainsString('height: 32px;', $regra);
    }

    #[TestDox('cor, realce e alinhamento (os outros `.bjed-b` do desenho) crescem junto, rótulo inclusive')]
    public function testSeletoresDeIconeCrescem(): void
    {
        $bloco = $this->blocoSemHover();

        $quadro = $this->regra($bloco, ".editor-rico .ql-snow .ql-color-picker,\n    .editor-rico .ql-snow .ql-icon-picker");
        self::assertStringContainsString('width: 34px;', $quadro);
        self::assertStringContainsString('height: 32px;', $quadro);

        $rotulo = $this->regra($bloco, ".editor-rico .ql-snow .ql-color-picker .ql-picker-label,\n    .editor-rico .ql-snow .ql-icon-picker .ql-picker-label");
        self::assertStringContainsString('width: 34px;', $rotulo);
    }

    #[TestDox('o @media vem depois das regras de 30×28 que ele sobrepõe (mesma especificidade)')]
    public function testMediaVemDepoisDoTamanhoPadrao(): void
    {
        $css = $this->css();
        $media = strpos($css, '@media (hover: none)');

        foreach ([
            ".editor-rico .ql-snow.ql-toolbar button {\n    float: none;\n    width: 30px;\n    height: 28px;",
            '.editor-rico .ql-snow .ql-icon-picker { width: 30px; }',
            ".editor-rico .ql-snow .ql-icon-picker .ql-picker-label {\n    width: 30px;",
        ] as $padrao) {
            $pos = strpos($css, $padrao);
            self::assertNotFalse($pos, "regra de 30×28 não encontrada: {$padrao}");
            self::assertGreaterThan($pos, $media);
        }
    }

    #[TestDox('o estilo do parágrafo e a mini barra do botão direito não mudam de tamanho')]
    public function testEstiloEMiniBarraFicamComoEstao(): void
    {
        $bloco = $this->blocoSemHover();

        self::assertStringNotContainsString('ql-header', $bloco);
        self::assertStringNotContainsString('editor-rico-menu', $bloco);
        self::assertStringNotContainsString('ql-picker {', $bloco, 'a regra genérica do seletor pegaria o estilo do parágrafo');
    }
}
