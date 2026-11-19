<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Com o Windows em "animações desligadas" (prefers-reduced-motion), o adminlte.min.css 4.0.0-rc3 põe
 * `.fade{display:block}`, e toda janela .modal.fade fechada cobria a tela, invisível, sem deixar clicar em nada
 * (bug de produção de 19/11/2026). O app.css, carregado depois do AdminLTE, devolve o `display:none` à janela
 * fechada. Que a tela volta a responder é o smoke do dono; aqui se prova a regra e a ordem dos arquivos.
 */
#[CoversNothing]
final class ModalComMovimentoReduzidoTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../';

    #[TestDox('app.css: na mídia de movimento reduzido, a janela .modal.fade fechada some')]
    public function testRegraDoModalFechado(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(self::RAIZ . 'public/css/app.css'));
        $achou = preg_match_all('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{((?:[^{}]*\{[^{}]*\})*)[^{}]*\}/', $css, $blocos);
        self::assertGreaterThan(0, $achou);

        $regra = '/\.modal\.fade:not\(\.show\)\s*\{\s*display:\s*none;?\s*\}/';
        self::assertNotEmpty(array_filter($blocos[1], static fn (string $b): bool => preg_match($regra, $b) === 1));
    }

    /** @return iterable<string, array{string}> */
    public static function layouts(): iterable
    {
        yield 'base' => ['templates/base.html.twig'];
        yield 'peticionar' => ['templates/layout_peticionar.html.twig'];
    }

    #[DataProvider('layouts')]
    #[TestDox('$_dataName: o app.css vem depois do adminlte.min.css')]
    public function testAppCssDepoisDoAdminLte(string $layout): void
    {
        $html = (string) file_get_contents(self::RAIZ . $layout);
        $adminlte = strpos($html, 'adminlte.min.css');
        $app = strpos($html, '/css/app.css');

        self::assertNotFalse($adminlte);
        self::assertNotFalse($app);
        self::assertGreaterThan($adminlte, $app);
    }
}
