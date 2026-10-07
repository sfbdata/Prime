<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * FPN-019: as cores do ponto da linha do tempo do Push vêm de tokens com par no tema escuro —
 * hex cravado direto na regra viraria cor de um tema no fundo do outro (cabeçalho da folha).
 * Aqui se lê a folha: cada modificador usa um `--ps-*` declarado em `.ps-page` e redefinido em
 * `[data-bs-theme="dark"] .ps-page`.
 */
#[Group('pasta')]
final class PastaPushPontoTokensTest extends TestCase
{
    private const CAMINHO_CSS = __DIR__ . '/../../../public/css/pasta-show.css';
    private const CLARO       = '.ps-page';
    private const ESCURO      = '[data-bs-theme="dark"] .ps-page';

    #[TestDox('juízo e cartório pintam o ponto por token, e todo token usado tem versão clara e escura')]
    public function testTokensDoPonto(): void
    {
        $css = (string) file_get_contents(self::CAMINHO_CSS);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $blocos, PREG_SET_ORDER);

        $usados = [];
        $claro  = [];
        $escuro = [];
        $modificadores = [];
        foreach ($blocos as [, $seletor, $corpo]) {
            $partes = array_map(static fn (string $p): string => (string) preg_replace('/\s+/', ' ', trim($p)), explode(',', $seletor));

            foreach ($partes as $parte) {
                if (preg_match('/^\.ps-push-ponto--(juizo|cartorio)$/', $parte, $mod) === 1 && str_contains($corpo, 'background')) {
                    $modificadores[$mod[1]] = true;
                    self::assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $corpo, "{$parte} não pode cravar hex");
                    preg_match_all('/var\(\s*(--ps-[a-z0-9-]+)/', $corpo, $m);
                    foreach ($m[1] as $token) {
                        $usados[$token] = true;
                    }
                }
            }

            preg_match_all('/(--ps-[a-z0-9-]+)\s*:/', $corpo, $decl);
            if (in_array(self::CLARO, $partes, true)) {
                $claro += array_fill_keys($decl[1], true);
            }
            if (in_array(self::ESCURO, $partes, true)) {
                $escuro += array_fill_keys($decl[1], true);
            }
        }

        self::assertArrayHasKey('juizo', $modificadores, 'a cor do juízo existe');
        self::assertArrayHasKey('cartorio', $modificadores, 'a cor do cartório existe');
        self::assertNotEmpty($usados);
        self::assertSame([], array_keys(array_diff_key($usados, $claro)), 'token sem declaração no tema claro');
        self::assertSame([], array_keys(array_diff_key($usados, $escuro)), 'token sem versão escura');
    }
}
