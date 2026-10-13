<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * C6-IA-02: as animações do drawer e da estrela, e o fundo escurecido, com os valores do
 * desenho (dc L.38-44 e L.842). O movimento em si só a tela mostra; aqui se prova que a folha
 * declara os keyframes do desenho, que cada elemento usa o seu e que o "reduzir movimento"
 * desliga também o fundo.
 */
#[Group('inteligencia')]
final class PastaIaAgentesAnimacaoCssTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/pasta-ia-agentes.css';

    private function css(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::CSS));
    }

    #[TestDox('os cinco keyframes têm os valores do desenho')]
    public function testKeyframes(): void
    {
        $css = $this->css();
        self::assertStringContainsString('@keyframes iaIn { from { transform: translate3d(100%, 0, 0); } to { transform: none; } }', $css);
        self::assertStringContainsString('@keyframes iaFade { from { opacity: 0; } to { opacity: 1; } }', $css);
        self::assertStringContainsString('@keyframes iaPulse { 0%, 100% { opacity: .55; transform: scale(.92); } 50% { opacity: 1; transform: scale(1.08); } }', $css);
        self::assertStringContainsString('@keyframes iaEstrela { 0%, 100% { transform: scale(1) rotate(0deg); filter: drop-shadow(0 0 0 rgba(31, 166, 204, 0)); } 45% { transform: scale(1.12) rotate(8deg); filter: drop-shadow(0 0 3px rgba(31, 166, 204, .65)); } }', $css);
        self::assertStringContainsString('@keyframes iaBrilho { 0%, 100% { opacity: .35; transform: scale(.7); } 45% { opacity: 1; transform: scale(1.15); } }', $css);

        self::assertStringNotContainsString('iaDrawerEntra', $css, 'o keyframe antigo do drawer saiu');
        self::assertStringNotContainsString('iaPulsa', $css, 'o keyframe antigo do pulso saiu');
    }

    #[TestDox('fundo rgba(10,30,45,.18) com iaFade .25s; drawer com iaIn; ícone do cabeçalho com iaPulse 2.4s')]
    public function testUso(): void
    {
        $css = $this->css();
        self::assertStringContainsString('--ia-fundo-escuro:  rgba(10, 30, 45, .18);', $css);
        self::assertMatchesRegularExpression('/^\.ia-drawer-fundo \{[^}]*background: var\(--ia-fundo-escuro\);[^}]*animation: iaFade \.25s ease both;/m', $css);
        self::assertMatchesRegularExpression('/^\.ia-drawer \{[^}]*animation: iaIn \.38s cubic-bezier\(\.22, \.8, \.3, 1\) both;/m', $css);
        self::assertStringContainsString('.ia-drawer-ico > i { font-size: 18px; animation: iaPulse 2.4s ease-in-out infinite; }', $css);
    }

    #[TestDox('reduzir movimento desliga também o fundo do drawer')]
    public function testReduzirMovimento(): void
    {
        $css    = $this->css();
        $inicio = strpos($css, '@media (prefers-reduced-motion: reduce) {');
        self::assertNotFalse($inicio);
        $bloco = substr($css, $inicio, (int) strpos($css, '}', $inicio) - $inicio);
        self::assertStringContainsString('.ia-drawer-fundo,', $bloco);
        self::assertStringContainsString('.ia-drawer,', $bloco);
    }
}
