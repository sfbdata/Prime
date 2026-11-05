<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\SanitizadorDePecaDeTexto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Auditoria 5.06: a peça escrita no TinyMCE é limpa no servidor antes de ir para o arquivo. O que
 * o editor produz fica; script, atributo on*, link javascript:, iframe e imagem de fora saem.
 */
#[CoversClass(SanitizadorDePecaDeTexto::class)]
final class SanitizadorDePecaDeTextoTest extends TestCase
{
    private const ESCRITORIO = 7;

    private SanitizadorDePecaDeTexto $sanitizador;

    protected function setUp(): void
    {
        $this->sanitizador = new SanitizadorDePecaDeTexto();
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function payloads(): iterable
    {
        yield 'script' => ['<p>a</p><script>alert(1)</script>', '<script'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>', 'onerror'];
        yield 'onclick em parágrafo' => ['<p onclick="alert(1)">a</p>', 'onclick'];
        yield 'link javascript:' => ['<a href="javascript:alert(1)">clique</a>', 'javascript:'];
        yield 'link javascript: com entidade' => ['<a href="jav&#x09;ascript:alert(1)">clique</a>', 'ascript:'];
        yield 'link data:' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>', 'data:'];
        yield 'iframe' => ['<iframe src="https://atacante.exemplo"></iframe><p>a</p>', 'iframe'];
        yield 'object' => ['<object data="x.swf"></object>', 'object'];
        yield 'svg' => ['<svg onload="alert(1)"><circle/></svg>', 'svg'];
        yield 'form' => ['<form action="https://atacante.exemplo"><input name="a"></form>', 'form'];
        yield 'style com url' => ['<p style="background:url(https://atacante.exemplo/p.png)">a</p>', 'url('];
        yield 'style com expression' => ['<p style="width:expression(alert(1))">a</p>', 'expression'];
        yield 'img externa' => ['<img src="https://atacante.exemplo/p.png">', 'atacante'];
        yield 'img data:' => ['<img src="data:image/png;base64,AAAA">', 'data:'];
        yield 'img travessia' => ['<img src="/uploads/pastas/../../config/x.png">', 'config'];
        yield 'img de outro escritório' => ['<img src="/uploads/pastas/99/abc.png">', '/99/'];
        yield 'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://atacante.exemplo">', 'refresh'];
    }

    #[DataProvider('payloads')]
    #[TestDox('Payload $_dataName sai do HTML limpo')]
    public function testPayloadSaiDoHtml(string $html, string $proibido): void
    {
        $limpo = $this->sanitizador->sanitizar($html, self::ESCRITORIO);

        self::assertStringNotContainsStringIgnoringCase($proibido, $limpo);
    }

    #[TestDox('O texto em volta do payload continua: o link perigoso perde o href, não as palavras')]
    public function testTextoDoLinkPerigosoFica(): void
    {
        $limpo = $this->sanitizador->sanitizar('<p>veja <a href="javascript:alert(1)">este processo</a></p>', self::ESCRITORIO);

        self::assertStringContainsString('este processo', $limpo);
        self::assertStringNotContainsString('href', $limpo);
    }

    /** @return iterable<string, array{0: string}> */
    public static function htmlDoEditor(): iterable
    {
        yield 'parágrafo simples' => ['<p>Conteúdo</p>'];
        yield 'negrito, itálico, sublinhado, tachado' => ['<p><strong>a</strong> <em>b</em> <span style="text-decoration: underline;">c</span> <s>d</s></p>'];
        yield 'alinhamento e fonte' => ['<p style="text-align: justify;"><span style="font-family: arial, helvetica, sans-serif; font-size: 14pt; color: #e03e2d; background-color: #fbeeb8;">texto</span></p>'];
        yield 'recuo' => ['<p style="padding-left: 40px;">recuo</p>'];
        yield 'títulos e pre' => ['<h1>T1</h1><h5>T5</h5><h6>T6</h6><pre>bloco</pre>'];
        yield 'listas e citação' => ['<ul><li>um</li></ul><ol><li>dois</li></ol><blockquote><p>citação</p></blockquote>'];
        yield 'subscrito, sobrescrito, código' => ['<p>H<sub>2</sub>O x<sup>2</sup> <code>art. 5</code></p>'];
        yield 'tabela do TinyMCE' => ['<table style="border-collapse: collapse; width: 100%;" border="1"><colgroup><col style="width: 50%;"><col style="width: 50%;"></colgroup><tbody><tr><td>a</td><td>b</td></tr></tbody></table>'];
        yield 'tabela com cabeçalho, legenda e mesclagem' => ['<table border="1"><caption>Legenda</caption><thead><tr><th scope="col">A</th><th scope="col">B</th></tr></thead><tbody><tr><td colspan="2" rowspan="1">x</td></tr></tbody><tfoot><tr><td>r</td><td>s</td></tr></tfoot></table>'];
        yield 'tabela simples do editor rico (#617)' => ['<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table>'];
        yield 'imagem enviada pelo editor (absoluta)' => ['<p><img src="/uploads/pastas/5f2b8c0e9a1d4e7f8a6b3c2d1e0f9a8b.png" alt="logo" width="200" height="100"></p>'];
        yield 'imagem enviada pelo editor (relativa)' => ['<p><img src="../../uploads/pastas/5f2b8c0e9a1d4e7f8a6b3c2d1e0f9a8b.png" alt=""></p>'];
        yield 'imagem na subpasta do próprio escritório' => ['<p><img src="/uploads/pastas/7/5f2b8c0e9a1d4e7f8a6b3c2d1e0f9a8b.png"></p>'];
        yield 'links http, https e mailto' => ['<p><a href="https://www.stf.jus.br/" target="_blank" rel="noopener">STF</a> <a href="http://exemplo.com.br/a">b</a> <a href="mailto:contato@exemplo.com.br">e-mail</a></p>'];
        yield 'âncora' => ['<p><a id="fundamentos"></a>Fundamentos</p>'];
        yield 'linha horizontal' => ['<p>a</p><hr><p>b</p>'];
        yield 'quebra de página' => ['<p>antes</p><!-- pagebreak --><p>depois</p>'];
    }

    #[DataProvider('htmlDoEditor')]
    #[TestDox('HTML legítimo do editor sai intacto: $_dataName')]
    public function testHtmlDoEditorSaiIntacto(string $html): void
    {
        $limpo = $this->sanitizador->sanitizar($html, self::ESCRITORIO);

        self::assertSame($this->normalizar($html), $this->normalizar($limpo));
    }

    #[TestDox('Peça vazia continua vazia')]
    public function testPecaVaziaContinuaVazia(): void
    {
        self::assertSame('', $this->sanitizador->sanitizar('', self::ESCRITORIO));
    }

    #[TestDox('Peça longa não é cortada (sem limite de tamanho que trunque o texto)')]
    public function testPecaLongaNaoEhCortada(): void
    {
        $html = str_repeat('<p>Parágrafo longo da peça, com bastante texto para passar do limite padrão.</p>', 5000);

        $limpo = $this->sanitizador->sanitizar($html, self::ESCRITORIO);

        self::assertSame(5000, substr_count($limpo, '<p>'));
    }

    /**
     * A comparação é pelo DOM, não pelos bytes: o sanitizador reescreve entidades (`=` vira `&#61;`),
     * aspas de atributo e o separador do `style` (`a: 1; b: 2;` vira `a: 1;b: 2`), o que não muda o
     * que o navegador e o export enxergam. Comentário entra na forma canônica (a quebra de página).
     */
    private function normalizar(string $html): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8"><div id="raiz">' . $html . '</div>', \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD);
        $raiz = $dom->getElementById('raiz');
        self::assertNotNull($raiz);

        return $this->canonico($raiz->childNodes);
    }

    /** @param \DOMNodeList<\DOMNode> $nos */
    private function canonico(\DOMNodeList $nos): string
    {
        $saida = '';
        foreach ($nos as $no) {
            if ($no instanceof \DOMText) {
                $saida .= $no->data;
            } elseif ($no instanceof \DOMComment) {
                $saida .= '<!--' . trim($no->data) . '-->';
            } elseif ($no instanceof \DOMElement) {
                $atributos = [];
                foreach ($no->attributes as $atributo) {
                    $valor = $atributo->name === 'style'
                        ? implode('; ', array_filter(array_map('trim', explode(';', $atributo->value)), static fn (string $d): bool => $d !== ''))
                        : $atributo->value;
                    $atributos[$atributo->name] = $atributo->name . '="' . $valor . '"';
                }
                ksort($atributos);
                $saida .= '<' . $no->tagName . ($atributos === [] ? '' : ' ' . implode(' ', $atributos)) . '>'
                    . $this->canonico($no->childNodes) . '</' . $no->tagName . '>';
            }
        }

        return $saida;
    }
}
