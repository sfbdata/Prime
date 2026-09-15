<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\ReferenciasDePecaHtml;
use PHPUnit\Framework\Attributes\CoversClass;
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

    #[TestDox('reescreverPrefixo troca o prefixo e preserva o nome — o comportamento do export')]
    public function testReescreverPrefixoPreservaONome(): void
    {
        $html = '<img src="../../uploads/pastas/abc123.png" />';

        $resultado = $this->servico->reescreverPrefixo($html, '/var/www/app/public/uploads/pastas/7/');

        self::assertSame('<img src="/var/www/app/public/uploads/pastas/7/abc123.png" />', $resultado);
    }

    #[TestDox('reescreverPrefixo não interpreta $ e \\ do caminho como referência de grupo')]
    public function testReescreverPrefixoComCaracteresEspeciais(): void
    {
        $html = '<img src="/uploads/pastas/x.png">';

        $resultado = $this->servico->reescreverPrefixo($html, '/tmp/a$1b\\2/');

        self::assertStringContainsString('/tmp/a$1b\\2/x.png', $resultado);
    }
}
