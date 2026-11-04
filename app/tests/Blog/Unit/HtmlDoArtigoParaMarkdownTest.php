<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Service\HtmlDoArtigoParaMarkdown;
use App\Blog\Service\ListaBrancaDoArtigo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlDoArtigoParaMarkdown::class)]
#[CoversClass(ListaBrancaDoArtigo::class)]
final class HtmlDoArtigoParaMarkdownTest extends TestCase
{
    private HtmlDoArtigoParaMarkdown $sut;

    protected function setUp(): void
    {
        $this->sut = new HtmlDoArtigoParaMarkdown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function blocosDaTabela(): iterable
    {
        yield 'aviso' => [
            '<div data-bj="aviso"><p>Confira a norma.</p></div>',
            ":::aviso\nConfira a norma.\n:::\n",
        ];
        yield 'aviso com título e lista vira um parágrafo só, como no site' => [
            '<div data-bj="aviso"><h2>Atenção</h2><p>Confira a norma.</p><ul><li>prazo</li><li>custas</li></ul></div>',
            ":::aviso\nAtenção Confira a norma. prazo custas\n:::\n",
        ];
        yield 'etapas' => [
            '<div data-bj="etapas"><ol><li><strong>Captura.</strong> Entram por um ponto.</li><li><strong>Cadastro.</strong> Na pasta.</li></ol></div>',
            ":::etapas\n1. **Captura.** Entram por um ponto.\n2. **Cadastro.** Na pasta.\n:::\n",
        ];
        yield 'checklist' => [
            '<div data-bj="checklist"><ul><li>Conferir.</li><li>Lançar.</li></ul></div>',
            ":::checklist\n- Conferir.\n- Lançar.\n:::\n",
        ];
        yield 'cta-meio' => [
            '<div data-bj="cta-meio" contenteditable="false">Chamada</div>',
            "::cta-meio\n",
        ];
        yield 'leia-tambem' => [
            '<div data-bj="leia-tambem" data-artigo="outro-artigo">Leia também</div>',
            "::leia-tambem{artigo=outro-artigo}\n",
        ];
        yield 'imagem' => [
            '<p><img src="/master/blog/imagens/12" alt="Painel de prazos"></p>',
            "![Painel de prazos](./imagens/12.webp)\n",
        ];
    }

    #[DataProvider('blocosDaTabela')]
    #[TestDox('Cada bloco da tabela da seção 3 vira exatamente o Markdown da diretiva ($_dataName)')]
    public function testBlocoDaTabela(string $html, string $esperado): void
    {
        self::assertSame($esperado, $this->sut->converter($html, [12 => 'webp']));
    }

    #[TestDox('Parágrafos, títulos, negrito, itálico, link, listas, citação e quebra de linha')]
    public function testFormatosBasicos(): void
    {
        $html = '<h2>Seção</h2><h3>Subseção</h3><p>Texto <b>forte</b> e <i>leve</i> com <a href="https://planalto.gov.br/a b">link</a>.</p>'
            . '<ul><li>um</li><li>dois<ul><li>dentro</li></ul></li></ul><ol><li>primeiro</li><li>segundo</li></ol>'
            . '<blockquote><p>Citação</p><p>Outra</p></blockquote><p>linha<br>nova</p>';

        $esperado = "## Seção\n\n### Subseção\n\nTexto **forte** e *leve* com [link](https://planalto.gov.br/a%20b).\n\n"
            . "- um\n- dois\n  - dentro\n\n1. primeiro\n2. segundo\n\n> Citação\n>\n> Outra\n\nlinha\\\nnova\n";

        self::assertSame($esperado, $this->sut->converter($html));
    }

    #[TestDox('Espaço na ponta do negrito fica fora da marca e mailto vale como link')]
    public function testEspacoForaDaMarca(): void
    {
        self::assertSame(
            "Um **forte** fim e [escreva](mailto:a@b.com).\n",
            $this->sut->converter('<p>Um<strong> forte </strong>fim e <a href="mailto:a@b.com">escreva</a>.</p>'),
        );
    }

    #[TestDox('O texto comum escapa os sinais do Markdown e os do começo da linha')]
    public function testEscapeDoMarkdown(): void
    {
        $html = '<p>a*b_c`d[e]f~g\\h</p><p># não é título</p><p>&gt; não é citação</p><p>- não é lista</p><p>1. não é lista</p><p>::cta-meio</p>';

        $esperado = "a\\*b\\_c\\`d\\[e\\]f\\~g\\\\h\n\n\\# não é título\n\n&gt; não é citação\n\n\\- não é lista\n\n1\\. não é lista\n\n\\::cta-meio\n";

        self::assertSame($esperado, $this->sut->converter($html));
    }

    #[TestDox('A lista branca derruba script, style, on*, style e class, e tag desconhecida deixa só o texto (tabela simples fica)')]
    public function testListaBranca(): void
    {
        $html = '<p class="x" style="color:red" onclick="alert(1)">Oi <span style="x">você</span></p><script>alert(1)</script><style>p{}</style>'
            . '<h1>Grande</h1><table><tr><td>a</td><td>b</td></tr></table>';

        // A tabela simples entra (botão Inserir tabela), sem atributo e com a primeira linha como cabeçalho.
        self::assertSame('<p>Oi você</p>Grande <table><thead><tr><th>a</th><th>b</th></tr></thead></table>', trim((string) preg_replace('/\s+/', ' ', $this->sut->sanitizar($html))));
        self::assertSame("Oi você\n\nGrande\n\n| a | b |\n| --- | --- |\n", $this->sut->converter($html));
    }

    #[TestDox('Imagem fora do mapa de extensões, de outro endereço ou sem id cai')]
    public function testImagemSemExtensaoCai(): void
    {
        self::assertSame("antes depois\n", $this->sut->converter('<p>antes <img src="/master/blog/imagens/7" alt="x"> depois</p>', [8 => 'png']));
        self::assertSame('', $this->sut->converter('<img src="https://outro.site/a.png" alt="x">', [8 => 'png']));
        self::assertSame('<p>a</p>', $this->sut->sanitizar('<p>a<img src="/master/blog/imagens/0" alt="x"></p>'));
    }

    #[TestDox('Bloco aninhado em outro bloco perde a moldura; leia-tambem com slug inválido cai')]
    public function testBlocosSoNoTopo(): void
    {
        $html = '<div data-bj="aviso"><p>Fora</p><div data-bj="cta-meio"></div></div><div data-bj="leia-tambem" data-artigo="Não Vale"></div><div data-bj="outro">texto</div>';

        self::assertSame(":::aviso\nFora\n:::\n\ntexto\n", $this->sut->converter($html));
    }

    #[TestDox('Etapas sem lista dentro fica como texto comum, sem a diretiva que o build recusaria')]
    public function testEtapasSemListaFicaTexto(): void
    {
        self::assertSame("Só texto.\n", $this->sut->converter('<div data-bj="etapas"><p>Só texto.</p></div>'));
    }

    #[TestDox('O sanitizar guarda os blocos, troca b por strong e i por em e conserva acentos')]
    public function testSanitizarGuardaOsBlocos(): void
    {
        $html = '<div data-bj="aviso" class="mce"><p><b>Atenção</b> à <i>regra</i></p></div><div data-bj="leia-tambem" data-artigo="outro">x</div>';

        self::assertSame(
            '<div data-bj="aviso"><p><strong>Atenção</strong> à <em>regra</em></p></div><div data-bj="leia-tambem" data-artigo="outro"></div>',
            $this->sut->sanitizar($html),
        );
    }

    #[TestDox('Ida e volta: o artigo controle-de-prazos-processuais em HTML sai igual ao Markdown do repositório dos sites')]
    public function testIdaEVoltaDoArtigoReal(): void
    {
        $markdown = (string) file_get_contents(__DIR__ . '/../Fixtures/controle-de-prazos-processuais.md');
        $corpoEsperado = (string) preg_replace('/^---\n.*?\n---\n/s', '', $markdown);
        $html = (string) file_get_contents(__DIR__ . '/../Fixtures/controle-de-prazos-processuais.html');

        $gerado = $this->sut->converter($html);

        self::assertSame(self::normalizar($corpoEsperado), self::normalizar($gerado));
        self::assertSame($gerado, $this->sut->converter($this->sut->sanitizar($html)), 'sanitizar e converter de novo não muda nada');
    }

    private static function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    #[TestDox('Sublista do botão de recuo (ul/ol dentro do li) sobrevive à lista branca e vira Markdown recuado')]
    public function testSublistaDoRecuoIdaEVolta(): void
    {
        // O que o TinyMCE produz ao aumentar o recuo de um item: a sublista entra dentro do item anterior.
        $html = '<ul><li>Documentos<ul><li>RG</li><li>CPF<ol><li>frente</li><li>verso</li></ol></li></ul></li><li>Custas</li></ul>'
            . '<ol><li>Petição<ul><li>assinada</li></ul></li><li>Protocolo</li></ol>';

        $limpo = $this->sut->sanitizar($html);
        self::assertSame($html, $limpo, 'a lista branca precisa manter a sublista como está');

        self::assertSame(
            "- Documentos\n  - RG\n  - CPF\n    1. frente\n    2. verso\n- Custas\n\n1. Petição\n   - assinada\n2. Protocolo\n",
            $this->sut->converter($limpo),
        );
    }
}
