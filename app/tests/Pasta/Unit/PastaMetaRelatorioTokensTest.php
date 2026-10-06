<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O drawer "Relatório da meta" vive no <body> (o pasta-metas.js o tira do painel da
 * aba). Token `--ps-*` declarado só em `.ps-page` não chegaria lá: a declaração
 * viraria inválida e o painel abriria TRANSPARENTE — o defeito que o
 * `PastaShowTokensDoDrawerTest` registrou para o drawer do histórico. Aqui se
 * confere a folha para as regras `.ps-mrel*`: todo token que elas usam tem de ser
 * declarado em `.ps-body` e redefinido no tema escuro.
 */
final class PastaMetaRelatorioTokensTest extends TestCase
{
    private const CAMINHO_CSS = __DIR__ . '/../../../public/css/pasta-show.css';
    private const DARK        = '[data-bs-theme="dark"] .ps-body';

    #[TestDox('todo token --ps-* das regras do drawer da meta é declarado em .ps-body e tem versão escura')]
    public function testTokensDoDrawerDaMeta(): void
    {
        $css = (string) file_get_contents(self::CAMINHO_CSS);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $blocos, PREG_SET_ORDER);

        $usados = [];
        $claro  = [];
        $escuro = [];
        foreach ($blocos as [, $seletor, $corpo]) {
            $partes = array_map(static fn (string $p) => (string) preg_replace('/\s+/', ' ', trim($p)), explode(',', $seletor));

            if (preg_match('/\.ps-mrel(?:-[a-z0-9-]+)?(?![a-z0-9-])/', $seletor) === 1) {
                preg_match_all('/var\(\s*(--ps-[a-z0-9-]+)/', $corpo, $m);
                foreach ($m[1] as $token) {
                    $usados[$token] = true;
                }
            }

            preg_match_all('/(--ps-[a-z0-9-]+)\s*:/', $corpo, $decl);
            if (in_array('.ps-body', $partes, true)) {
                $claro += array_fill_keys($decl[1], true);
            }
            if (in_array(self::DARK, $partes, true)) {
                $escuro += array_fill_keys($decl[1], true);
            }
        }

        self::assertNotEmpty($usados, 'nenhuma regra .ps-mrel encontrada: o teste perdeu o alvo');
        self::assertSame([], array_keys(array_diff_key($usados, $claro)), 'token sem declaração em .ps-body: o drawer abriria transparente');
        self::assertSame([], array_keys(array_diff_key($usados, $escuro)), 'token sem versão escura: cor do tema claro dentro do drawer no escuro');
    }
}
