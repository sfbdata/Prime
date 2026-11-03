<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Service\HtmlDoArtigoParaMarkdown;
use App\Blog\Service\ListaBrancaDoArtigo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O Astro repassa o HTML cru que estiver no Markdown: o conversor nunca pode emitir HTML (Coordenador, 03/11).
 * Cada caso é algo colado no editor; a saída do Markdown não pode ter `<` literal nem `javascript:`, e o
 * sanitizar() segue as mesmas regras.
 */
#[CoversClass(HtmlDoArtigoParaMarkdown::class)]
#[CoversClass(ListaBrancaDoArtigo::class)]
final class ConversorXssTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function colados(): iterable
    {
        yield 'script' => ['<p>antes</p><script>alert(1)</script><p>depois</p>'];
        yield 'img com onerror' => ['<p>foto <img src=x onerror=alert(1)> fim</p>'];
        yield 'img interna com onerror' => ['<p><img src="/master/blog/imagens/3" onerror="alert(1)" alt="a<b>c"></p>'];
        yield 'link javascript' => ['<p><a href="javascript:alert(1)">clique</a></p>'];
        yield 'link JaVaScRiPt com espaço e controle' => ["<p><a href=\" \x01JaVaScRiPt:alert(1)\">clique</a></p>"];
        yield 'link com tab no meio do esquema' => ["<p><a href=\"java\tscript:alert(1)\">clique</a></p>"];
        yield 'link com entidade no esquema' => ['<p><a href="&#106;avascript:alert(1)">clique</a></p>'];
        yield 'link data' => ['<p><a href="data:text/html;base64,PHNjcmlwdD4=">clique</a></p>'];
        yield 'link vbscript' => ['<p><a href="VBScript:msgbox(1)">clique</a></p>'];
        yield 'texto <b>& escrito como texto' => ['<p>&lt;b&gt;&amp; escrito</p>'];
        yield 'texto de link com tag escrita' => ['<p><a href="https://a.b">&lt;script&gt;x</a></p>'];
        yield 'style' => ['<style>body{background:url(javascript:alert(1))}</style><p>ok</p>'];
        yield 'svg com script' => ['<svg><script>alert(1)</script><a href="javascript:alert(1)">x</a></svg>'];
        yield 'iframe' => ['<iframe src="javascript:alert(1)"></iframe><p>ok</p>'];
        yield 'comentário condicional' => ['<!--[if IE]><script>alert(1)</script><![endif]--><p>ok</p>'];
    }

    #[DataProvider('colados')]
    #[TestDox('Colado no editor ($_dataName): o Markdown sai sem "<" literal e sem "javascript:"')]
    public function testMarkdownSemHtml(string $html): void
    {
        $markdown = (new HtmlDoArtigoParaMarkdown())->converter($html, [3 => 'png']);

        self::assertStringNotContainsString('<', $markdown);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $markdown);
        self::assertStringNotContainsStringIgnoringCase('vbscript:', $markdown);
        self::assertStringNotContainsStringIgnoringCase('data:', $markdown);
        self::assertStringNotContainsString('alert(1)', $markdown);
    }

    #[DataProvider('colados')]
    #[TestDox('Colado no editor ($_dataName): o sanitizar() deixa só tags da lista branca, sem on*, script ou esquema perigoso')]
    public function testSanitizarSemHtmlPerigoso(string $html): void
    {
        $limpo = (new HtmlDoArtigoParaMarkdown())->sanitizar($html);

        self::assertDoesNotMatchRegularExpression('/<(?!\/?(p|h2|h3|strong|em|a|ul|ol|li|blockquote|img|br|div)[\s>\/])/i', $limpo);
        self::assertDoesNotMatchRegularExpression('/\son\w+\s*=/i', $limpo);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $limpo);
        self::assertStringNotContainsStringIgnoringCase('vbscript:', $limpo);
        self::assertStringNotContainsStringIgnoringCase('data:', $limpo);
        self::assertStringNotContainsString('alert(1)', $limpo);
    }

    #[TestDox('O texto "<b>&" escrito como texto sai em entidades, no texto, no alt e no texto do link')]
    public function testEntidadesNoTextoNoAltENoLink(): void
    {
        $sut = new HtmlDoArtigoParaMarkdown();

        self::assertSame("&lt;b&gt;&amp; escrito\n", $sut->converter('<p>&lt;b&gt;&amp; escrito</p>'));
        self::assertSame("![a&lt;b&gt;c](./imagens/3.png)\n", $sut->converter('<p><img src="/master/blog/imagens/3" alt="a<b>c"></p>', [3 => 'png']));
        self::assertSame("[&lt;b&gt; site](https://a.b/?x=1&y=2)\n", $sut->converter('<p><a href="https://a.b/?x=1&amp;y=2">&lt;b&gt; site</a></p>'));
    }

    #[TestDox('Link com esquema perigoso deixa só o texto; caminho relativo vale')]
    public function testLinkPerigosoFicaTexto(): void
    {
        $sut = new HtmlDoArtigoParaMarkdown();

        self::assertSame("clique\n", $sut->converter('<p><a href=" JaVaScRiPt:alert(1)">clique</a></p>'));
        self::assertSame('<p>clique</p>', $sut->sanitizar('<p><a href=" JaVaScRiPt:alert(1)">clique</a></p>'));
        self::assertSame("[outro](/blog/prazos-processuais/outro)\n", $sut->converter('<p><a href="/blog/prazos-processuais/outro">outro</a></p>'));
    }
}
