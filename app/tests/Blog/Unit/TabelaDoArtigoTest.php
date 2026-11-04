<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Service\HtmlDoArtigoParaMarkdown;
use App\Blog\Service\ListaBrancaDoArtigo;
use App\Blog\Service\MarkdownDoArtigoParaHtml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Tabela simples do editor do blog (botão Inserir tabela): lista branca, HTML para GFM, GFM para HTML e a ida e volta.
 */
#[CoversClass(ListaBrancaDoArtigo::class)]
#[CoversClass(HtmlDoArtigoParaMarkdown::class)]
#[CoversClass(MarkdownDoArtigoParaHtml::class)]
final class TabelaDoArtigoTest extends TestCase
{
    private const TABELA = '<table><thead><tr><th>Prazo</th><th>Dias</th></tr></thead>'
        . '<tbody><tr><td>Contestação</td><td>15</td></tr><tr><td>Apelação</td><td>15</td></tr></tbody></table>';

    private HtmlDoArtigoParaMarkdown $html;
    private MarkdownDoArtigoParaHtml $markdown;

    protected function setUp(): void
    {
        $this->html = new HtmlDoArtigoParaMarkdown();
        $this->markdown = new MarkdownDoArtigoParaHtml();
    }

    /** @return iterable<string, array{string, string}> */
    public static function tabelasNaListaBranca(): iterable
    {
        yield 'a forma do editor fica como está' => [self::TABELA, self::TABELA];
        yield 'atributo e estilo somem' => [
            '<table border="1" style="width: 100%; border-collapse: collapse" class="x" data-mce-id="1"><colgroup><col style="width: 50%"></colgroup>'
                . '<thead><tr style="height: 20px"><th scope="col" colspan="2" style="color: red">A</th></tr></thead>'
                . '<tbody><tr><td rowspan="2" onclick="alert(1)">1</td></tr></tbody></table>',
            '<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>',
        ];
        yield 'bloco na célula vira texto e imagem cai' => [
            '<table><thead><tr><th><p>Um</p><p>Dois</p></th></tr></thead><tbody><tr><td><h2>T</h2><ul><li>item</li></ul>'
                . '<img src="/master/blog/imagens/3" alt="x"><blockquote>cit</blockquote></td></tr></tbody></table>',
            '<table><thead><tr><th>Um Dois </th></tr></thead><tbody><tr><td>T item  cit </td></tr></tbody></table>',
        ];
        yield 'inline da célula fica: strong, em, link e br' => [
            '<table><tbody><tr><td><b>a</b> <i>b</i> <a href="https://x.com" target="_blank">c</a><br>d<span>e</span></td></tr></tbody></table>',
            '<table><thead><tr><th><strong>a</strong> <em>b</em> <a href="https://x.com">c</a><br>de</th></tr></thead></table>',
        ];
        yield 'link perigoso na célula vira texto' => [
            '<table><tr><td><a href="javascript:alert(1)">x</a></td></tr></table>',
            '<table><thead><tr><th>x</th></tr></thead></table>',
        ];
        yield 'sem thead: a primeira linha vira o cabeçalho' => [
            '<table><tbody><tr><td>A</td><td>B</td></tr><tr><th>1</th><td>2</td></tr></tbody></table>',
            '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>',
        ];
        yield 'outras linhas do thead e o tfoot vão para o tbody' => [
            '<table><thead><tr><th>A</th></tr><tr><th>B</th></tr></thead><tbody><tr><td>C</td></tr></tbody><tfoot><tr><td>D</td></tr></tfoot></table>',
            '<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>B</td></tr><tr><td>C</td></tr><tr><td>D</td></tr></tbody></table>',
        ];
        yield 'tabela dentro de tabela vira texto' => [
            '<table><tbody><tr><td>fora<table><tr><td>x</td><td>y</td></tr></table></td></tr></tbody></table>',
            '<table><thead><tr><th>fora   x  y   </th></tr></thead></table>',
        ];
        yield 'tabela fora do topo vira texto' => [
            '<ul><li><table><tr><td>a</td><td>b</td></tr></table></li></ul>',
            '<ul><li>   a  b   </li></ul>',
        ];
        yield 'tabela sem célula some e linha sem célula também' => [
            '<p>x</p><table><tbody><tr></tr></tbody></table><table><tr></tr><tr><td>só</td></tr></table>',
            '<p>x</p><table><thead><tr><th>só</th></tr></thead></table>',
        ];
        yield 'script na célula some com o texto' => [
            '<table><tr><td>a<script>alert(1)</script></td></tr></table>',
            '<table><thead><tr><th>a</th></tr></thead></table>',
        ];
    }

    #[DataProvider('tabelasNaListaBranca')]
    #[TestDox('Lista branca da tabela: $_dataName')]
    public function testListaBranca(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, $this->html->sanitizar($entrada));
    }

    /** @return iterable<string, array{string, string}> */
    public static function tabelasEmGfm(): iterable
    {
        yield 'cabeçalho, separação e linhas' => [
            self::TABELA,
            "| Prazo | Dias |\n| --- | --- |\n| Contestação | 15 |\n| Apelação | 15 |\n",
        ];
        yield 'barra no texto sai escapada' => [
            '<table><thead><tr><th>a|b</th></tr></thead><tbody><tr><td>c \| d</td></tr></tbody></table>',
            "| a\\|b |\n| --- |\n| c \\\\\\| d |\n",
        ];
        yield 'célula vazia e linha curta ganham célula vazia' => [
            '<table><thead><tr><th>A</th><th></th><th>C</th></tr></thead><tbody><tr><td>1</td></tr><tr><td>&nbsp;</td><td>2</td><td>3</td></tr></tbody></table>',
            "| A |  | C |\n| --- | --- | --- |\n| 1 |  |  |\n|  | 2 | 3 |\n",
        ];
        yield 'cabeçalho mais curto que o corpo ganha célula vazia' => [
            '<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>',
            "| A |  |\n| --- | --- |\n| 1 | 2 |\n",
        ];
        yield 'sem thead: a primeira linha vira o cabeçalho' => [
            '<table><tr><td>A</td><td>B</td></tr><tr><td>1</td><td>2</td></tr></table>',
            "| A | B |\n| --- | --- |\n| 1 | 2 |\n",
        ];
        yield 'só o cabeçalho' => ['<table><thead><tr><th>A</th></tr></thead></table>', "| A |\n| --- |\n"];
        yield 'negrito, itálico e link; br vira espaço' => [
            '<table><thead><tr><th><strong>Lei</strong></th></tr></thead><tbody><tr><td><em>art.</em> 5<br>ver <a href="https://planalto.gov.br/a|b">texto</a></td></tr></tbody></table>',
            "| **Lei** |\n| --- |\n| *art.* 5 ver [texto](https://planalto.gov.br/a\\|b) |\n",
        ];
        yield 'sinais do Markdown e HTML na célula saem escapados' => [
            '<table><thead><tr><th># &lt;b&gt; *x*</th></tr></thead></table>',
            "| # &lt;b&gt; \\*x\\* |\n| --- |\n",
        ];
        yield 'entre parágrafos' => [
            '<p>Antes</p>' . self::TABELA . '<p>Depois</p>',
            "Antes\n\n| Prazo | Dias |\n| --- | --- |\n| Contestação | 15 |\n| Apelação | 15 |\n\nDepois\n",
        ];
    }

    #[DataProvider('tabelasEmGfm')]
    #[TestDox('HTML para GFM: $_dataName')]
    public function testHtmlParaGfm(string $html, string $markdown): void
    {
        self::assertSame($markdown, $this->html->converter($html));
    }

    /** @return iterable<string, array{string, string}> */
    public static function gfmEmHtml(): iterable
    {
        yield 'tabela do conversor' => [
            "| Prazo | Dias |\n| --- | --- |\n| Contestação | 15 |\n| Apelação | 15 |\n",
            self::TABELA,
        ];
        yield 'sem as barras das pontas e com alinhamento' => [
            "A | B\n:--- | ---:\n1 | 2\n",
            '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>',
        ];
        yield 'barra escapada, célula vazia, linha curta e longa' => [
            "| a\\|b | c |\n|---|:-:|\n| 1 |  |\n| 2 |\n| 3 | 4 | 5 |\n",
            '<table><thead><tr><th>a|b</th><th>c</th></tr></thead><tbody><tr><td>1</td><td></td></tr><tr><td>2</td><td></td></tr>'
                . '<tr><td>3</td><td>4</td></tr></tbody></table>',
        ];
        yield 'só o cabeçalho' => ["| A |\n| --- |\n", '<table><thead><tr><th>A</th></tr></thead></table>'];
        yield 'inline na célula e texto escapado' => [
            "| **Lei** | &lt;b&gt; |\n| --- | --- |\n| [texto](https://x.com/a\\|b) | \\*x\\* |\n",
            '<table><thead><tr><th><strong>Lei</strong></th><th>&lt;b&gt;</th></tr></thead>'
                . '<tbody><tr><td><a href="https://x.com/a|b">texto</a></td><td>*x*</td></tr></tbody></table>',
        ];
        yield 'termina na linha em branco' => [
            "| A |\n| --- |\n| 1 |\n\nDepois",
            "<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>\n<p>Depois</p>",
        ];
        yield 'separação com número de células diferente não é tabela' => ["| A | B |\n| --- |\n", '<p>| A | B | | --- |</p>'];
    }

    #[DataProvider('gfmEmHtml')]
    #[TestDox('GFM para HTML: $_dataName')]
    public function testGfmParaHtml(string $markdown, string $html): void
    {
        self::assertSame($html, $this->markdown->converter($markdown));
    }

    #[TestDox('Ida e volta: HTML da tabela, Markdown e HTML de novo saem iguais')]
    public function testIdaEVoltaPeloHtml(): void
    {
        $html = '<p>Os prazos:</p><table><thead><tr><th>Ato</th><th>Prazo | dias</th><th></th></tr></thead>'
            . '<tbody><tr><td><strong>Contestação</strong></td><td>15</td><td><a href="https://planalto.gov.br">CPC</a></td></tr>'
            . '<tr><td><em>Embargos</em> \\ declaração</td><td>5</td><td></td></tr></tbody></table><p>Fim.</p>';

        $markdown = $this->html->converter($html);
        // o conversor de volta separa os blocos com quebra de linha; a lista branca a mantém como texto entre eles
        $deVolta = (string) preg_replace('/>\s+</', '><', $this->html->sanitizar($this->markdown->converter($markdown)));

        self::assertSame($this->html->sanitizar($html), $deVolta);
        self::assertSame($markdown, $this->html->converter($deVolta));
    }

    #[TestDox('Ida e volta: o Markdown GFM, em HTML e de volta, sai igual')]
    public function testIdaEVoltaPeloMarkdown(): void
    {
        $markdown = "Texto.\n\n| A | B \\| C |\n| --- | --- |\n| **1** |  |\n| *2* | [x](https://x.com) |\n\nFim.\n";

        self::assertSame($markdown, $this->html->converter($this->html->sanitizar($this->markdown->converter($markdown))));
    }
}
