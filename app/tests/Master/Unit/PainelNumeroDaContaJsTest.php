<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Número da conta no Painel do Master (spec perfis-1.2-permissoes-e-hierarquia A3; desenho 05 - Perfis 1.2 - VS
 * 21.10.26, L165, L173 e L752), lido como FOLHA no padrão de PainelPlanosELimitesJsTest: sem navegador no PHPUnit,
 * prova-se o contrato do public/js/master/painel.js e do public/css/master.css com o desenho. Desde a P2b do idioma
 * (spec idioma-do-sistema), o texto mora no catálogo (master.js.contas.*) e o teste confere a chave no JS e o texto lá.
 */
#[CoversNothing]
final class PainelNumeroDaContaJsTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../';

    private static function js(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/js/master/painel.js');
    }

    /** Texto em português de uma chave do catálogo. */
    private static function catalogo(string $chave): string
    {
        $no = Yaml::parseFile(self::RAIZ . 'translations/messages+intl-icu.pt_BR.yaml');
        foreach (explode('.', $chave) as $parte) {
            self::assertIsArray($no, $chave);
            self::assertArrayHasKey($parte, $no, $chave);
            $no = $no[$parte];
        }
        self::assertIsString($no, $chave);

        return $no;
    }

    private static function css(): string
    {
        return (string) file_get_contents(self::RAIZ . 'public/css/master.css');
    }

    #[TestDox('L165: placeholder "Buscar nº, conta ou primário"; o antigo saiu')]
    public function testPlaceholder(): void
    {
        $js = self::js();

        self::assertStringContainsString("placeholder=\"' + T('master.js.contas.busca') + '\" aria-label=\"' + T('master.js.contas.busca') + '\"", $js);
        self::assertSame('Buscar nº, conta ou primário', self::catalogo('master.js.contas.busca'));
        self::assertStringNotContainsString('"Buscar conta ou primário"', $js);
    }

    #[TestDox('L173: selo do número antes do nome, com o título e os valores do desenho')]
    public function testSelo(): void
    {
        self::assertStringContainsString(
            "'<div class=\"mp-conta-nome mp-hv\"><span class=\"mp-conta-titulo\"><span class=\"mp-conta-nro\" title=\"' + T('master.js.contas.numero_dica') + '\">' + esc(T('master.js.contas.numero', { numero: c.numero })) + '</span><b title=\"' + esc(c.nome) + '\">' + esc(c.nome) + '</b></span><span>' + esc(T('master.js.contas.desde', ",
            self::js(),
        );
        self::assertSame('Número da conta (ordem de abertura)', self::catalogo('master.js.contas.numero_dica'));
        self::assertSame('Nº {numero}', self::catalogo('master.js.contas.numero'));
        self::assertStringStartsWith('desde {desde}', self::catalogo('master.js.contas.desde'));

        $css = self::css();
        self::assertStringContainsString('.mp-conta-titulo { display: flex; align-items: baseline; gap: 7px; min-width: 0; }', $css);
        self::assertStringContainsString('.mp-conta-nro { flex: none; font: bold 11.5px/1 monospace; color: #0a6fa0; background: #e5f1f7; border: 1px solid #cfe3f0; border-radius: 3px; padding: 3px 5px; }', $css);
        self::assertStringContainsString('.mp-conta-titulo > b { font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }', $css);
        // A linha "desde ... · último acesso" continua com o estilo dela (é o último span da célula).
        self::assertStringContainsString('.mp-conta-nome > span:last-child { font-size: 11.5px; color: #5b7383; }', $css);
    }

    #[TestDox('L752: só dígitos (com ou sem "nº"/"n°" e zeros à esquerda) casa o número EXATO; nome e primário por trecho')]
    public function testBusca(): void
    {
        $js = self::js();
        $funcao = self::funcao($js, 'casaNaBusca');

        // Sem node no container (e sem precedente de teste JS executado), a regra se prova no texto da função.
        self::assertStringContainsString("const q = b.replace(/^n\\s*[º°o]?\\s*/, '');", $funcao, 'tira o "nº"/"n°" do começo');
        self::assertStringContainsString('if (/^\\d+$/.test(q) && Number(q) === Number(c.numero)) return true;', $funcao, 'número exato: "12" não acha 000120, "000" não acha todas');
        self::assertStringContainsString("return (c.nome + ' ' + (c.titular || '')).toLowerCase().includes(b);", $funcao, 'nome e primário por trecho');
        self::assertStringNotContainsString('c.numero +', $funcao, 'o número nunca entra no casamento por trecho');
        self::assertStringNotContainsString('includes(q)', $funcao);
        self::assertStringContainsString('const b = S.busca.toLowerCase().trim();', $js);
        self::assertStringContainsString('(!b || casaNaBusca(c, b))', $js);
    }

    private static function funcao(string $js, string $nome): string
    {
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertNotFalse($inicio);
        $fim = strpos($js, "\n  }\n", $inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }
}
