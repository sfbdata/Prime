<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O X de fechar em toda janelinha (protótipo bluejus-central.js; public/js/fechar-janelas.js), lido no texto: só entra
 * onde não há fechar, reconhece o do Bootstrap e não esconde nada à força. O comportamento é do e2e/tests/fechar-janelas.spec.js.
 */
final class FecharJanelasJsTest extends TestCase
{
    private static function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/fechar-janelas.js');
    }

    /** O código sem os comentários: o cabeçalho cita innerHTML e display:none justamente para dizer que não usa. */
    private static function codigo(): string
    {
        return (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', self::js());
    }

    #[TestDox('Já tem fechar: os do protótipo e os do Bootstrap (.btn-close, data-bs-dismiss, "Close"), sem X em dobro')]
    public function testReconheceOsFecharesExistentes(): void
    {
        $js = self::js();
        foreach (['[aria-label="Fechar"]', '[aria-label^="Fechar "]', '[title^="Fechar"]', '[data-bj-x]', '.bi-x-lg', '.btn-close', '[data-bs-dismiss]', '[aria-label="Close"]'] as $seletor) {
            self::assertStringContainsString($seletor, $js);
        }
        self::assertStringContainsString("if (!jan || jan.querySelector(TEM_X)) { return; }", $js);
        self::assertStringContainsString("el.closest('[data-sem-x]')", $js, 'data-sem-x desliga');
    }

    #[TestDox('Abre por classe (modal do Bootstrap), fundo pelo primeiro de verdade, nada escondido à força, sem innerHTML')]
    public function testDiferencasDoPrototipo(): void
    {
        $js = self::js();
        self::assertStringContainsString("attributeFilter: ['class', 'style', 'hidden']", $js);
        self::assertStringContainsString('for (var el = host; el && el !== document.documentElement; el = el.parentElement) {', $js);
        self::assertStringNotContainsString('display', self::codigo(), 'nada escondido à força');
        self::assertStringContainsString("var PAPEIS = '[role=\"menu\"],[role=\"dialog\"],[role=\"alertdialog\"]';", self::codigo(), 'sem listbox: o X cairia dentro da primeira opção (no Select2, escolhia a opção)');
        self::assertStringContainsString('&& !h.matches(ITEM);', self::codigo(), 'nunca dentro de um item clicável');
        self::assertStringContainsString("['pointerdown', 'mousedown', 'pointerup', 'mouseup']", self::codigo());
        self::assertStringContainsString('if (!aVista(jan)) { return; }', self::codigo(), 'clique fora só com a janela ainda à vista');
        self::assertSame(1, substr_count(self::codigo(), "new KeyboardEvent('keydown'"), 'Esc uma vez só');
        self::assertStringNotContainsString('innerHTML', self::codigo());
        self::assertDoesNotMatchRegularExpression('/[–—]/u', $js);
    }
}
