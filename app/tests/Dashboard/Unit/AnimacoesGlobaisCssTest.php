<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra global de movimento reduzido mora no app.css (carregado pelo base.html.twig) e o JS das
 * preferências liga a classe no <html> na hora, sem recarregar.
 */
#[CoversNothing]
final class AnimacoesGlobaisCssTest extends TestCase
{
    private static function ler(string $relativo): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3) . '/' . $relativo);
    }

    #[TestDox('app.css desliga animação e transição em html.jp-sem-anim e em prefers-reduced-motion')]
    public function testRegraGlobal(): void
    {
        $css = self::ler('public/css/app.css');

        self::assertMatchesRegularExpression(
            '/html\.jp-sem-anim \*,\s*html\.jp-sem-anim \*::before,\s*html\.jp-sem-anim \*::after \{[^}]*animation-duration: \.01ms !important;[^}]*transition-duration: \.01ms !important;/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{\s*\*,\s*\*::before,\s*\*::after \{[^}]*animation-duration: \.01ms !important;[^}]*transition-duration: \.01ms !important;/s',
            $css,
        );
    }

    #[TestDox('O base.html.twig põe jp-sem-anim no <html> pela preferência')]
    public function testBasePoeAClasse(): void
    {
        self::assertStringContainsString(
            '<html lang="pt-BR" data-bs-theme="light"{% if animacoes_reduzidas() %} class="jp-sem-anim"{% endif %}>',
            self::ler('templates/base.html.twig'),
        );
    }

    #[TestDox('Trocar a opção no Dashboard liga ou desliga a classe no <html> na hora')]
    public function testJsAlternaNoHtml(): void
    {
        self::assertStringContainsString(
            "document.documentElement.classList.toggle('jp-sem-anim', lista.indexOf('db-page--sem-anim') !== -1);",
            self::ler('public/js/dashboard-preferencias.js'),
        );
    }
}
