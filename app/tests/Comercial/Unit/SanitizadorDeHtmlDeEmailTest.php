<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\SanitizadorDeEstiloInline;
use App\Comercial\Campanha\SanitizadorDeHtmlDeEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O HTML importado (spec §8.3, §8.7): script, evento, `javascript:`, `url(` no style e `<style>` caem; o e-mail fica. */
#[CoversClass(SanitizadorDeHtmlDeEmail::class)]
#[CoversClass(SanitizadorDeEstiloInline::class)]
final class SanitizadorDeHtmlDeEmailTest extends TestCase
{
    private function limpar(string $html): string
    {
        return (new SanitizadorDeHtmlDeEmail())->sanitizar($html);
    }

    #[TestDox('Tira script, iframe, form, object e os atributos de evento')]
    public function testTiraScriptEEventos(): void
    {
        $html = $this->limpar('<p onclick="roubar()" onmouseover="x()">Oi</p><script>alert(1)</script><iframe src="https://x.test"></iframe><form action="https://x.test"><input name="a"></form><object data="x.swf"></object>');

        self::assertSame('<p>Oi</p>', $html);
    }

    #[TestDox('Links só http(s) e mailto: javascript: e data: perdem o href')]
    public function testLinks(): void
    {
        $html = $this->limpar('<a href="javascript:alert(1)">a</a><a href="data:text/html,x">b</a><a href="https://ok.test/p">c</a><a href="mailto:a@b.test">d</a>');

        self::assertStringNotContainsString('javascript', $html);
        self::assertStringNotContainsString('data:', $html);
        self::assertStringContainsString('href="https://ok.test/p"', $html);
        self::assertStringContainsString('href="mailto:a&#64;b.test"', $html);
    }

    #[TestDox('O style fica, menos as declarações com url(, expression(, @import e escape de CSS')]
    public function testEstiloInline(): void
    {
        $html = $this->limpar('<td style="color:#fff; background:url(https://rastreio.test/p.gif); width: expression(alert(1)); padding:4px; b\\61 ckground: red">x</td>');

        self::assertStringContainsString('color:#fff;padding:4px', $html);
        self::assertStringNotContainsString('url(', $html);
        self::assertStringNotContainsString('expression', $html);
        self::assertSame('font-size:12px', SanitizadorDeEstiloInline::limpar('font-size:12px; @import "x.css"'));
        self::assertSame('', SanitizadorDeEstiloInline::limpar('background: u/**/rl(x)'));
        // Outras funções de CSS que buscam imagem de fora (revisão da 2a, M1).
        self::assertSame('color:red', SanitizadorDeEstiloInline::limpar('color:red; background-image:image-set("https://x.test/a.png" 1x)'));
        self::assertSame('', SanitizadorDeEstiloInline::limpar('background:-webkit-image-set("https://x.test/a.png" 1x)'));
        self::assertSame('', SanitizadorDeEstiloInline::limpar('background:cross-fade("https://x.test/a.png", red 50%)'));
    }

    #[TestDox('O documento inteiro vira o conteúdo do body; <head> e <style> caem e o aviso é detectado')]
    public function testDocumentoInteiro(): void
    {
        $original = '<!doctype html><html><head><title>Informe &amp; cia</title><style>p{color:red}</style></head><body style="background:#eee"><table width="600"><tr><td align="center">Olá {nome}</td></tr></table></body></html>';
        $html = $this->limpar($original);

        self::assertSame('<div style="background:#eee"><table width="600"><tr><td align="center">Olá {nome}</td></tr></table></div>', $html);
        self::assertTrue(SanitizadorDeHtmlDeEmail::temEstiloEmBloco($original));
        self::assertFalse(SanitizadorDeHtmlDeEmail::temEstiloEmBloco('<p>sem</p>'));
        self::assertSame('Informe & cia', SanitizadorDeHtmlDeEmail::titulo($original));
        self::assertNull(SanitizadorDeHtmlDeEmail::titulo('<p>sem título</p>'));
    }

    #[TestDox('As tags de e-mail ficam: tabela, imagem https, center, font')]
    public function testTagsDeEmail(): void
    {
        $html = $this->limpar('<table cellpadding="0" cellspacing="0" role="presentation"><tr><td bgcolor="#fff" valign="top"><img src="https://i.test/a.png" alt="A" width="100"><center><font color="red">c</font></center></td></tr></table>');

        self::assertStringContainsString('<img src="https://i.test/a.png" alt="A" width="100" />', $html);
        self::assertStringContainsString('<center><font color="red">c</font></center>', $html);
        self::assertStringContainsString('cellpadding="0"', $html);
    }
}
