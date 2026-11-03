<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Exception\ArtigoDoRepositorioRecusado;
use App\Blog\Service\HtmlDoArtigoParaMarkdown;
use App\Blog\Service\MarkdownDoArtigoParaHtml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownDoArtigoParaHtml::class)]
#[CoversClass(ArtigoDoRepositorioRecusado::class)]
final class MarkdownDoArtigoParaHtmlTest extends TestCase
{
    private MarkdownDoArtigoParaHtml $sut;

    protected function setUp(): void
    {
        $this->sut = new MarkdownDoArtigoParaHtml();
    }

    /** @return iterable<string, array{string, string}> */
    public static function blocos(): iterable
    {
        yield 'aviso' => [":::aviso\nConfira a norma.\n:::\n", '<div data-bj="aviso"><p>Confira a norma.</p></div>'];
        yield 'etapas' => [
            ":::etapas\n1. **Captura.** Entram por um ponto.\n2. **Cadastro.** Na pasta.\n:::\n",
            '<div data-bj="etapas"><ol><li><strong>Captura.</strong> Entram por um ponto.</li><li><strong>Cadastro.</strong> Na pasta.</li></ol></div>',
        ];
        yield 'checklist' => [
            ":::checklist\n- Conferir.\n- Lançar.\n:::\n",
            '<div data-bj="checklist"><ul><li>Conferir.</li><li>Lançar.</li></ul></div>',
        ];
        yield 'cta-meio' => ["::cta-meio\n", '<div data-bj="cta-meio"></div>'];
        yield 'leia-tambem' => ["::leia-tambem{artigo=outro-artigo}\n", '<div data-bj="leia-tambem" data-artigo="outro-artigo"></div>'];
        yield 'seção e subseção' => ["## Seção\n\n### Subseção\n", "<h2>Seção</h2>\n<h3>Subseção</h3>"];
        yield 'parágrafos de várias linhas' => ["Primeira linha\ncontinua.\n\nSegundo.\n", "<p>Primeira linha continua.</p>\n<p>Segundo.</p>"];
        yield 'negrito, itálico e link' => [
            'Um **forte**, um *leve*, um _outro_ e [o site](https://bluejus.com.br/blog) com nome_de_arquivo.',
            '<p>Um <strong>forte</strong>, um <em>leve</em>, um <em>outro</em> e <a href="https://bluejus.com.br/blog">o site</a> com nome_de_arquivo.</p>',
        ];
        yield 'negrito dentro do link e link relativo' => ['[**Prazos**](/blog/prazos)', '<p><a href="/blog/prazos"><strong>Prazos</strong></a></p>'];
        yield 'lista com sublista' => [
            "- um\n- dois\n  1. dois a\n  2. dois b\n- três\n",
            '<ul><li>um</li><li>dois<ol><li>dois a</li><li>dois b</li></ol></li><li>três</li></ul>',
        ];
        yield 'lista numerada' => ["1. um\n2. dois\n", '<ol><li>um</li><li>dois</li></ol>'];
        yield 'citação' => ["> Um trecho\n> da lei.\n>\n> Outro.\n", '<blockquote><p>Um trecho da lei.</p><p>Outro.</p></blockquote>'];
        yield 'quebra de linha com barra e com dois espaços' => ["Linha um\\\nLinha dois  \nLinha três", '<p>Linha um<br>Linha dois<br>Linha três</p>'];
    }

    #[DataProvider('blocos')]
    #[TestDox('Cada bloco vira exatamente o HTML da tabela da seção 3 ($_dataName)')]
    public function testBloco(string $markdown, string $esperado): void
    {
        self::assertSame($esperado, $this->sut->converter($markdown));
    }

    #[TestDox('Texto sai escapado: <, > e & viram entidade, a barra do Markdown some e entidade escrita continua texto')]
    public function testEscape(): void
    {
        self::assertSame('<p>a &lt; b &amp; c &gt; d</p>', $this->sut->converter('a < b & c > d'));
        self::assertSame('<p>*não* é [link]</p>', $this->sut->converter('\*não\* é \[link\]'));
        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $this->sut->converter('&lt;script&gt;alert(1)&lt;/script&gt;'));
    }

    /** @return iterable<string, array{string}> */
    public static function linksPerigosos(): iterable
    {
        yield 'javascript' => ['[clique](javascript:alert(1))'];
        yield 'maiúsculas' => ['[clique](JavaScript:alert(1))'];
        yield 'entidade no esquema' => ['[clique](javascript&#58;alert(1))'];
        yield 'barra no esquema' => ['[clique](javascript\:alert(1))'];
        yield 'data' => ['[clique](data:text/html;base64,PHNjcmlwdD4=)'];
        yield 'vbscript' => ['[clique](vbscript:msgbox)'];
    }

    #[DataProvider('linksPerigosos')]
    #[TestDox('Link com esquema perigoso cai e fica só o texto ($_dataName)')]
    public function testLinkPerigosoCai(string $markdown): void
    {
        self::assertSame('<p>clique</p>', $this->sut->converter($markdown));
    }

    /** @return iterable<string, array{string, string}> */
    public static function foraDoSubconjunto(): iterable
    {
        yield 'imagem' => ['Veja ![painel](./imagens/painel.png)', 'imagem'];
        yield 'bloco de código' => ["```\ncodigo\n```", 'bloco de código'];
        yield 'título de nível 1' => ['# Título', 'título de nível'];
        yield 'título de nível 4' => ['#### Título', 'título de nível'];
        yield 'linha horizontal' => ["Texto\n\n---\n\nMais", 'linha horizontal'];
        yield 'HTML no texto' => ['Um <script>alert(1)</script>', 'HTML no meio do texto'];
        yield 'diretiva desconhecida' => ["::video{id=1}\n", 'Bloco desconhecido'];
        yield 'diretiva de bloco desconhecida' => [":::galeria\n- a\n:::\n", 'Bloco desconhecido'];
        yield 'diretiva sem fechamento' => [":::aviso\nTexto\n", 'não tem a linha'];
        yield 'leia também com slug inválido' => ["::leia-tambem{artigo=Outro Artigo}\n", 'Leia também'];
    }

    #[DataProvider('foraDoSubconjunto')]
    #[TestDox('O que o editor não guarda recusa o artigo com mensagem ($_dataName)')]
    public function testForaDoSubconjuntoRecusa(string $markdown, string $mensagem): void
    {
        $this->expectException(ArtigoDoRepositorioRecusado::class);
        $this->expectExceptionMessage($mensagem);
        $this->sut->converter($markdown);
    }

    #[TestDox('O corpo do artigo real vira o mesmo HTML do editor (fixture .html, sem o rótulo dos blocos não editáveis)')]
    public function testArtigoRealViraOHtmlDoEditor(): void
    {
        $md = (string) file_get_contents(__DIR__ . '/../Fixtures/controle-de-prazos-processuais.md');
        $corpo = (string) preg_replace('/^---\n.*?\n---\n/s', '', $md);
        $editor = (string) file_get_contents(__DIR__ . '/../Fixtures/controle-de-prazos-processuais.html');
        $sanitizador = new HtmlDoArtigoParaMarkdown();

        $semEspacoEntreTags = static fn (string $html): string => (string) preg_replace('/>\s+</', '><', trim($html));
        self::assertSame(
            $semEspacoEntreTags($sanitizador->sanitizar($editor)),
            $semEspacoEntreTags($sanitizador->sanitizar($this->sut->converter($corpo))),
        );
    }

    #[TestDox('Ida e volta: o Markdown do artigo real, convertido em HTML e de volta pelo conversor do domínio, sai igual')]
    public function testIdaEVolta(): void
    {
        $md = (string) file_get_contents(__DIR__ . '/../Fixtures/controle-de-prazos-processuais.md');
        $corpo = ltrim((string) preg_replace('/^---\n.*?\n---\n/s', '', $md));
        $conversor = new HtmlDoArtigoParaMarkdown();

        self::assertSame($corpo, $conversor->converter($conversor->sanitizar($this->sut->converter($corpo))));
    }
}
