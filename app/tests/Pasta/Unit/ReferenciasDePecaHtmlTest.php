<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\ReferenciasDePecaHtml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenciasDePecaHtml::class)]
final class ReferenciasDePecaHtmlTest extends TestCase
{
    private ReferenciasDePecaHtml $servico;

    protected function setUp(): void
    {
        $this->servico = new ReferenciasDePecaHtml();
    }

    #[TestDox('Acha a imagem quando o TinyMCE gravou a URL ABSOLUTA')]
    public function testUrlAbsoluta(): void
    {
        $html = '<p>Texto</p><img src="/uploads/pastas/abc123.png" />';

        self::assertSame(['abc123.png'], $this->servico->extrair($html));
    }

    #[TestDox('Acha a imagem quando o TinyMCE gravou a URL RELATIVA (default convert_urls)')]
    public function testUrlRelativa(): void
    {
        $html = '<img src="../../uploads/pastas/abc123.png" />';

        self::assertSame(['abc123.png'], $this->servico->extrair($html));
    }

    #[TestDox('Acha a imagem dentro da subpasta do tenant (isolamento M5)')]
    public function testUrlComSubpastaDoTenant(): void
    {
        $html = '<img src="/uploads/pastas/7/abc123.png" />';

        self::assertSame(['abc123.png'], $this->servico->extrair($html));
    }

    #[TestDox('Acha as três variantes no mesmo HTML, sem repetir')]
    public function testVariantesMisturadasSemRepeticao(): void
    {
        $html = '<img src="/uploads/pastas/um.png"><img src="../../uploads/pastas/dois.jpg">'
            . '<img src="./uploads/pastas/3/tres.png"><img src="/uploads/pastas/um.png">';

        $nomes = $this->servico->extrair($html);
        sort($nomes);

        self::assertSame(['dois.jpg', 'tres.png', 'um.png'], $nomes);
    }

    #[TestDox('HTML sem imagem alguma devolve lista vazia')]
    public function testHtmlSemImagem(): void
    {
        self::assertSame([], $this->servico->extrair('<p>Só texto.</p>'));
        self::assertSame([], $this->servico->extrair(''));
    }

    #[TestDox('Não confunde outro diretório de uploads com uploads/pastas')]
    public function testNaoPegaOutroDiretorio(): void
    {
        $html = '<img src="/uploads/perfil/foto.png"><img src="/uploads/clientes/doc.pdf">';

        self::assertSame([], $this->servico->extrair($html));
    }

    // ----------------------------------------------- allowlist do export (E2.6C, D32)

    /** @return iterable<string, array{string}> */
    public static function referenciasRecusadas(): iterable
    {
        yield 'url http com cara de caminho local' => ['http://malicioso.invalido/uploads/pastas/x.png'];
        yield 'url https' => ['https://malicioso.invalido/x.png'];
        yield 'protocolo file' => ['file:///etc/hostname'];
        yield 'data uri' => ['data:image/png;base64,QUJD'];
        yield 'caminho absoluto de disco' => ['/etc/hostname'];
        yield 'travessia literal' => ['/uploads/pastas/../9/x.png'];
        yield 'travessia percent-encoded' => ['/uploads/pastas/%2e%2e/9/x.png'];
        yield 'barra dupla no começo' => ['//host/uploads/pastas/x.png'];
        yield 'barra invertida' => ['\\uploads\\pastas\\x.png'];
        yield 'caixa trocada' => ['/UPLOADS/PASTAS/x.png'];
        yield 'subdiretório no nome' => ['/uploads/pastas/sub/x.png'];
        yield 'barra dupla no meio' => ['/uploads/pastas/7//x.png'];
        yield 'query string' => ['/uploads/pastas/x.png?v=2'];
        yield 'fragmento' => ['/uploads/pastas/x.png#a'];
        yield 'entidade html no lugar da barra' => ['&#47;uploads&#47;pastas&#47;x.png'];
        yield 'outra categoria' => ['/uploads/clientes/x.png'];
        yield 'nome começando com ponto' => ['/uploads/pastas/.oculto.png'];
        yield 'vazio' => [''];
    }

    #[DataProvider('referenciasRecusadas')]
    #[TestDox('o export recusa $_dataName')]
    public function testReferenciaRecusada(string $src): void
    {
        self::assertNull((new ReferenciasDePecaHtml())->nomeDeImagemDoEscritorio($src, 7));
    }

    /** @return iterable<string, array{string, string}> */
    public static function referenciasAceitas(): iterable
    {
        yield 'absoluta sem subpasta (peça legada)' => ['/uploads/pastas/foto.png', 'foto.png'];
        yield 'absoluta com o meu escritório' => ['/uploads/pastas/7/foto.png', 'foto.png'];
        yield 'relativa do TinyMCE' => ['../../uploads/pastas/7/foto.png', 'foto.png'];
        yield 'relativa com ./' => ['./uploads/pastas/foto.png', 'foto.png'];
        yield 'sem barra inicial' => ['uploads/pastas/foto.png', 'foto.png'];
        yield 'com espaços em volta' => ['  /uploads/pastas/foto.png  ', 'foto.png'];
    }

    #[DataProvider('referenciasAceitas')]
    #[TestDox('o export aceita $_dataName e extrai o nome')]
    public function testReferenciaAceita(string $src, string $esperado): void
    {
        self::assertSame($esperado, (new ReferenciasDePecaHtml())->nomeDeImagemDoEscritorio($src, 7));
    }

    #[TestDox('a subpasta de OUTRO escritório é recusada — o escopo não vem da URL')]
    public function testSubpastaDeOutroEscritorio(): void
    {
        $referencias = new ReferenciasDePecaHtml();

        self::assertNull($referencias->nomeDeImagemDoEscritorio('/uploads/pastas/9/foto.png', 7));
        self::assertNull($referencias->nomeDeImagemDoEscritorio('/uploads/pastas/7/foto.png', null), 'sem escritório, subpasta nenhuma serve');
        self::assertSame('foto.png', $referencias->nomeDeImagemDoEscritorio('/uploads/pastas/foto.png', null));
    }
}
