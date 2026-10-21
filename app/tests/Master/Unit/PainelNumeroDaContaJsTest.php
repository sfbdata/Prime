<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Número da conta no Painel do Master (spec perfis-1.2-permissoes-e-hierarquia A3; desenho 05 - Perfis 1.2 - VS
 * 21.10.26, L165, L173 e L752), lido como FOLHA no padrão de PainelPlanosELimitesJsTest: sem navegador no PHPUnit,
 * prova-se o contrato do public/js/master/painel.js e do public/css/master.css com o desenho.
 */
#[CoversNothing]
final class PainelNumeroDaContaJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/painel.js');
    }

    private static function css(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/css/master.css');
    }

    #[TestDox('L165: placeholder "Buscar nº, conta ou primário"; o antigo saiu')]
    public function testPlaceholder(): void
    {
        $js = self::js();

        self::assertStringContainsString('placeholder="Buscar nº, conta ou primário" aria-label="Buscar nº, conta ou primário"', $js);
        self::assertStringNotContainsString('"Buscar conta ou primário"', $js);
    }

    #[TestDox('L173: selo do número antes do nome, com o título e os valores do desenho')]
    public function testSelo(): void
    {
        self::assertStringContainsString(
            "'<div class=\"mp-conta-nome mp-hv\"><span class=\"mp-conta-titulo\"><span class=\"mp-conta-nro\" title=\"Número da conta (ordem de abertura)\">Nº ' + esc(c.numero) + '</span><b title=\"' + esc(c.nome) + '\">' + esc(c.nome) + '</b></span><span>desde '",
            self::js(),
        );

        $css = self::css();
        self::assertStringContainsString('.mp-conta-titulo { display: flex; align-items: baseline; gap: 7px; min-width: 0; }', $css);
        self::assertStringContainsString('.mp-conta-nro { flex: none; font: bold 11.5px/1 monospace; color: #0a6fa0; background: #e5f1f7; border: 1px solid #cfe3f0; border-radius: 3px; padding: 3px 5px; }', $css);
        self::assertStringContainsString('.mp-conta-titulo > b { font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }', $css);
        // A linha "desde ... · último acesso" continua com o estilo dela (é o último span da célula).
        self::assertStringContainsString('.mp-conta-nome > span:last-child { font-size: 11.5px; color: #5b7383; }', $css);
    }

    #[TestDox('L752: a busca casa o número com ou sem zeros à esquerda, além do nome e do primário')]
    public function testBusca(): void
    {
        $js = self::js();

        self::assertStringContainsString('function casaNaBusca(c, b) {', $js);
        self::assertStringContainsString("if (/^\\d+$/.test(b) && Number(b) === Number(c.numero)) return true;", $js);
        self::assertStringContainsString("return ('nº ' + c.numero + ' ' + c.nome + ' ' + (c.titular || '')).toLowerCase().includes(b);", $js);
        self::assertStringContainsString("const b = S.busca.toLowerCase().trim();", $js);
        self::assertStringContainsString('(!b || casaNaBusca(c, b))', $js);
    }
}
