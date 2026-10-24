<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * EDT-010r: o pasta-mencoes.js liga TODOS os compositores marcados com `[data-mencionaveis-url]`
 * (Registro, Detalhes e Relatório financeiro), cada um com a URL da própria caixa. Antes ele
 * ligava só o primeiro da página — o do Registro. É JS sem runner aqui: a prova é o contrato do
 * arquivo, como no `EditorRicoSugestaoTest`.
 */
#[CoversNothing]
final class PastaMencoesJsTest extends TestCase
{
    private const ARQUIVO = __DIR__ . '/../../../public/js/pasta-mencoes.js';

    #[TestDox('EDT-010r: liga cada compositor marcado, com a URL da própria caixa')]
    public function testLigaTodosOsCompositores(): void
    {
        $js = (string) file_get_contents(self::ARQUIVO);
        $ini = (int) strpos($js, 'function ligarCompositor(tentativas) {');
        $fn  = substr($js, $ini, (int) strpos($js, "\n    }\n", $ini) - $ini);

        self::assertStringContainsString("document.querySelectorAll('[data-mencionaveis-url]').forEach(function (caixa) {", $fn);
        self::assertStringContainsString("textarea._mencoesUrl = caixa.getAttribute('data-mencionaveis-url');", $fn);
        self::assertStringContainsString('if (!textarea || textarea._mencoesLigado) { return; }', $fn, 'não liga duas vezes');
        self::assertStringNotContainsString("document.querySelector('[data-mencionaveis-url]')", $fn);
    }

    #[TestDox('Campo simples do despacho (C3): o nome é procurado e gravado no texto puro, sem escHtml; "&" e "<" no nome viram token')]
    public function testCampoSimplesNaoEscapaONome(): void
    {
        $fn = $this->funcao('function paraEnvio(textarea, html) {');

        self::assertMatchesRegularExpression(
            "/if \\(textarea\\.hasAttribute && textarea\\.hasAttribute\\('data-mencoes-simples'\\)\\) \\{\\s*return trocarMencoes\\(String\\(html\\), lista, function \\(s\\) \\{ return String\\(s\\); \\}\\);\\s*\\}/",
            $fn,
            'texto puro: identidade, sem corte por tag (um "<" no texto não é tag)',
        );
        self::assertLessThan(strpos($fn, 'split('), strpos($fn, "data-mencoes-simples"), 'o campo simples sai antes do corte por tag do HTML');
    }

    #[TestDox('Editor rico da pasta: segue procurando o nome escapado (&amp;) só nos trechos de texto do HTML')]
    public function testEditorRicoSegueEscapando(): void
    {
        $fn = $this->funcao('function paraEnvio(textarea, html) {');
        self::assertStringContainsString("return String(html).split(/(<[^>]*>)/).map(function (parte) {", $fn);
        self::assertStringContainsString('return trocarMencoes(parte, lista, escHtml);', $fn);

        $troca = $this->funcao('function trocarMencoes(parte, lista, esc) {');
        self::assertStringContainsString("var re = new RegExp('@' + escRegex(esc(m.nome)) + '(?![A-Za-zÀ-ÿ0-9\\\\]])', 'g');", $troca);
        self::assertStringContainsString("return '@[' + esc(rotulo(m.nome)) + '](user:' + m.id + ')';", $troca);
        self::assertStringNotContainsString('escHtml', $troca, 'o escape vem de quem chama');
    }

    private function funcao(string $inicio): string
    {
        $js = (string) file_get_contents(self::ARQUIVO);
        $ini = strpos($js, $inicio);
        self::assertNotFalse($ini, $inicio);

        return substr($js, $ini, (int) strpos($js, "\n    }\n", $ini) - $ini);
    }
}
