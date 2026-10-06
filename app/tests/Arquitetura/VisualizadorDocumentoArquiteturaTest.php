<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Trava o desenho do visualizador de documentos (public/js/visualizador-documento.js):
 *
 *  - um módulo só — as telas não voltam a ter a própria cópia do preview;
 *  - o HTML gerado por mammoth/SheetJS vai para iframe `sandbox=""` com CSP, nunca para o DOM
 *    da página (o arquivo é de cliente: um DOCX/XLSX é entrada hostil);
 *  - as bibliotecas são auto-hospedadas, com a licença ao lado.
 *
 * Regex não executa JS: isto prova que as peças estão lá, não que o navegador as usa. O
 * comportamento na tela é smoke do dono.
 */
#[CoversNothing]
final class VisualizadorDocumentoArquiteturaTest extends TestCase
{
    private const MODULO = 'public/js/visualizador-documento.js';

    private static function raiz(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function modulo(): string
    {
        $conteudo = file_get_contents(self::raiz() . '/' . self::MODULO);
        self::assertIsString($conteudo);

        return $conteudo;
    }

    #[TestDox('o HTML gerado vai para iframe sandbox="" (sem allow-scripts / allow-same-origin) via srcdoc')]
    public function testHtmlGeradoVaiParaIframeSandbox(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("setAttribute('sandbox', '')", $js);
        self::assertStringContainsString('.srcdoc = ', $js);
        self::assertStringNotContainsString('allow-scripts', $js);
        self::assertStringNotContainsString('allow-same-origin', $js);
    }

    #[TestDox('o srcdoc abre com a CSP que só deixa imagem data: e estilo inline')]
    public function testSrcdocTemCsp(): void
    {
        $js = self::modulo();

        self::assertStringContainsString(
            "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
            $js
        );
        self::assertStringContainsString('<meta http-equiv="Content-Security-Policy"', $js);
    }

    #[TestDox('o módulo nunca ATRIBUI innerHTML/outerHTML nem usa insertAdjacentHTML/document.write')]
    public function testNuncaInjetaHtmlNoDom(): void
    {
        $js = self::modulo();

        self::assertDoesNotMatchRegularExpression('/\.(inner|outer)HTML\s*\+?=(?!=)/', $js);
        self::assertStringNotContainsString('insertAdjacentHTML', $js);
        self::assertStringNotContainsString('document.write', $js);
    }

    #[TestDox('as bibliotecas vêm de /js/vendor (auto-hospedadas), nunca de CDN')]
    public function testBibliotecasSaoAutoHospedadas(): void
    {
        $js = self::modulo();

        self::assertStringContainsString("'mammoth/mammoth.browser.min.js'", $js);
        self::assertStringContainsString("'xlsx/xlsx.full.min.js'", $js);
        self::assertDoesNotMatchRegularExpression('#https?://#', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js) ?? '');
    }

    /** @return iterable<string, array{string}> */
    public static function arquivosVendor(): iterable
    {
        yield 'mammoth (js)'      => ['public/js/vendor/mammoth/mammoth.browser.min.js'];
        yield 'mammoth (licença)' => ['public/js/vendor/mammoth/LICENSE'];
        yield 'SheetJS (js)'      => ['public/js/vendor/xlsx/xlsx.full.min.js'];
        yield 'SheetJS (licença)' => ['public/js/vendor/xlsx/LICENSE'];
    }

    #[DataProvider('arquivosVendor')]
    #[TestDox('cada arquivo auto-hospedado (biblioteca e licença) existe e não está vazio')]
    public function testArquivoVendorExiste(string $arquivo): void
    {
        $caminho = self::raiz() . '/' . $arquivo;

        self::assertFileExists($caminho);
        self::assertGreaterThan(0, filesize($caminho));
    }

    /** @return iterable<string, array{string}> */
    public static function telasComPreview(): iterable
    {
        yield 'pasta'   => ['templates/pasta/show.html.twig'];
        yield 'cliente' => ['templates/cliente/show.html.twig'];
        yield 'tarefa'  => ['templates/tarefa/show.html.twig'];
    }

    #[DataProvider('telasComPreview')]
    #[TestDox('as telas de pasta, cliente e tarefa não têm mais cópia própria do preview — delegam ao módulo')]
    public function testTelaNaoTemCopiaDoPreview(string $template): void
    {
        $twig = file_get_contents(self::raiz() . '/' . $template);
        self::assertIsString($twig);

        self::assertStringContainsString('VisualizadorDocumento.ligarModal(', $twig);
        self::assertStringNotContainsString('previewConteudo.innerHTML', $twig);
        self::assertStringNotContainsString("addEventListener('show.bs.modal'", self::trechoDoPreview($twig));
    }

    /** As 40 linhas em volta da chamada ao módulo — onde a cópia antiga morava. */
    private static function trechoDoPreview(string $twig): string
    {
        $pos = strpos($twig, 'VisualizadorDocumento.ligarModal(');
        self::assertNotFalse($pos);

        return substr($twig, max(0, $pos - 1500), 3000);
    }
}
