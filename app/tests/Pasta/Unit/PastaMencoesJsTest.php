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
}
