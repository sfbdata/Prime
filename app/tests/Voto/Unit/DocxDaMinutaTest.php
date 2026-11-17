<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Voto\Exception\ModeloDeAtaInvalidoException;
use App\Voto\Service\DocxDaMinuta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * O .docx da minuta (desenho 18, bj-voto-ata.js `gerar`): com modelo, o corpo entra no `<w:body>` antes do `<w:sectPr>`
 * e o timbre (cabeçalho, rodapé) fica; sem modelo, um .docx simples; modelo inválido recusado em cada caso.
 */
#[CoversClass(DocxDaMinuta::class)]
final class DocxDaMinutaTest extends TestCase
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const CORPO = '<w:p><w:r><w:t>CORPO DA ATA</w:t></w:r></w:p>';
    private const PREFIXO = 'voto.assembleia.gestor.minuta.';

    private DocxDaMinuta $docx;

    protected function setUp(): void
    {
        $this->docx = new DocxDaMinuta(new IdentityTranslator());
    }

    #[TestDox('Sem modelo: gera um .docx simples com o corpo antes do sectPr')]
    public function testSemModelo(): void
    {
        $arquivos = $this->ler($this->docx->montar(self::CORPO));

        self::assertSame(['[Content_Types].xml', '_rels/.rels', 'word/document.xml'], array_keys($arquivos));
        $documento = $arquivos['word/document.xml'];
        self::assertLessThan(strpos($documento, '<w:sectPr'), strpos($documento, 'CORPO DA ATA'));
        self::assertNotFalse(simplexml_load_string($documento), 'o document.xml é XML válido');
    }

    #[TestDox('Com modelo: o corpo entra no body antes do sectPr, o texto do modelo sai e cabeçalho, rodapé e imagem ficam')]
    public function testComModelo(): void
    {
        $modelo = $this->zip([
            '[Content_Types].xml' => '<Types/>',
            'word/header1.xml' => '<w:hdr>TIMBRE</w:hdr>',
            'word/footer1.xml' => '<w:ftr>RODAPE</w:ftr>',
            'word/media/logo.png' => "\x89PNG-logo",
            'word/document.xml' => $this->documento('<w:p><w:r><w:t>TEXTO DO MODELO</w:t></w:r></w:p>'),
        ]);

        $arquivos = $this->ler($this->docx->montar(self::CORPO, $modelo));

        self::assertSame('<w:hdr>TIMBRE</w:hdr>', $arquivos['word/header1.xml']);
        self::assertSame('<w:ftr>RODAPE</w:ftr>', $arquivos['word/footer1.xml']);
        self::assertSame("\x89PNG-logo", $arquivos['word/media/logo.png']);
        $documento = $arquivos['word/document.xml'];
        self::assertStringNotContainsString('TEXTO DO MODELO', $documento);
        self::assertMatchesRegularExpression('#<w:body>' . preg_quote(self::CORPO, '#') . '<w:sectPr><w:headerReference w:type="default" r:id="rId1"/>#', $documento);
        self::assertNotFalse(simplexml_load_string($documento));
    }

    #[TestDox('Modelo cujo body tem atributos também é reconhecido, e vale o último sectPr (o do corpo)')]
    public function testBodyComAtributoEUltimoSectPr(): void
    {
        $xml = '<?xml version="1.0"?><w:document xmlns:w="' . self::W . '"><w:body lang="pt"><w:p><w:pPr><w:sectPr><w:pgSz/></w:sectPr></w:pPr></w:p><w:sectPr><w:titlePg/></w:sectPr></w:body></w:document>';

        $documento = $this->ler($this->docx->montar(self::CORPO, $this->zip(['word/document.xml' => $xml])))['word/document.xml'];

        self::assertStringContainsString('<w:body lang="pt">' . self::CORPO . '<w:sectPr><w:titlePg/></w:sectPr></w:body>', $documento);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function modelosInvalidos(): iterable
    {
        $w = 'xmlns:w="' . self::W . '"';
        yield 'não é zip' => ['isto não é um docx', 'modelo_invalido'];
        yield 'começa com PK mas está quebrado' => ["PK\x03\x04quebrado", 'modelo_invalido'];
        yield 'zip sem document.xml' => [self::zipEstatico(['word/header1.xml' => '<w:hdr/>']), 'modelo_sem_documento'];
        yield 'sem body' => [self::zipEstatico(['word/document.xml' => '<w:document ' . $w . '><w:sectPr/></w:document>']), 'modelo_nao_reconhecido'];
        yield 'sem sectPr' => [self::zipEstatico(['word/document.xml' => '<w:document ' . $w . '><w:body><w:p/></w:body></w:document>']), 'modelo_nao_reconhecido'];
        yield 'sectPr antes do body' => [self::zipEstatico(['word/document.xml' => '<w:document ' . $w . '><w:sectPr/><w:body></w:body></w:document>']), 'modelo_nao_reconhecido'];
        yield 'com DOCTYPE' => [self::zipEstatico(['word/document.xml' => '<!DOCTYPE x [<!ENTITY e "y">]><w:document ' . $w . '><w:body><w:sectPr/></w:body></w:document>']), 'modelo_nao_reconhecido'];
        yield 'só sectPrChange, sem sectPr de verdade' => [self::zipEstatico(['word/document.xml' => '<w:document ' . $w . '><w:body><w:p/><w:sectPrChange w:id="1"/></w:body></w:document>']), 'modelo_nao_reconhecido'];
        yield 'sectPr só dentro de parágrafo, não filho do body' => [self::zipEstatico(['word/document.xml' => '<w:document ' . $w . '><w:body><w:p><w:pPr><w:sectPr><w:pgSz/></w:sectPr></w:pPr></w:p></w:body></w:document>']), 'modelo_nao_reconhecido'];
        yield 'sem fechar o body' => [self::zipEstatico(['word/document.xml' => '<w:document ' . $w . '><w:body><w:sectPr/></w:document>']), 'modelo_nao_reconhecido'];
        yield 'acima do limite' => [str_repeat('x', DocxDaMinuta::TAMANHO_MAXIMO_DO_MODELO + 1), 'modelo_grande'];
    }

    #[TestDox('sectPr final com sectPrChange aninhado (ou autofechado): vale o filho direto do body, por inteiro')]
    public function testSectPrFinalComMudancaAninhada(): void
    {
        $w = 'xmlns:w="' . self::W . '"';
        $final = '<w:sectPr w:rsidR="1"><w:pgSz/><w:sectPrChange w:id="2"><w:sectPr><w:pgSz/></w:sectPr></w:sectPrChange></w:sectPr>';
        $modelo = $this->zip(['word/document.xml' => '<w:document ' . $w . '><w:body><w:p>velho</w:p>' . $final . '</w:body></w:document>']);

        $documento = $this->ler($this->docx->montar(self::CORPO, $modelo))['word/document.xml'];

        self::assertStringContainsString('<w:body>' . self::CORPO . $final . '</w:body>', $documento);

        $autofechado = $this->zip(['word/document.xml' => '<w:document ' . $w . '><w:body><w:p>velho</w:p><w:sectPr w:rsidR="1"/></w:body></w:document>']);
        self::assertStringContainsString('<w:body>' . self::CORPO . '<w:sectPr w:rsidR="1"/></w:body>', $this->ler($this->docx->montar(self::CORPO, $autofechado))['word/document.xml']);
    }

    #[DataProvider('modelosInvalidos')]
    #[TestDox('Modelo que não serve é recusado com a mensagem do desenho')]
    public function testModeloInvalido(string $modelo, string $chave): void
    {
        try {
            $this->docx->montar(self::CORPO, $modelo);
            self::fail('devia recusar o modelo');
        } catch (ModeloDeAtaInvalidoException $e) {
            self::assertSame(self::PREFIXO . $chave, $e->getMessage());
        }
    }

    private function documento(string $miolo): string
    {
        return '<?xml version="1.0"?><w:document xmlns:w="' . self::W . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>' . $miolo
            . '<w:sectPr><w:headerReference w:type="default" r:id="rId1"/><w:pgSz w:w="11906"/></w:sectPr></w:body></w:document>';
    }

    /** @param array<string, string> $arquivos */
    private function zip(array $arquivos): string
    {
        return self::zipEstatico($arquivos);
    }

    /** @param array<string, string> $arquivos */
    private static function zipEstatico(array $arquivos): string
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($caminho, \ZipArchive::OVERWRITE);
        foreach ($arquivos as $nome => $conteudo) {
            $zip->addFromString($nome, $conteudo);
        }
        $zip->close();
        $bytes = (string) file_get_contents($caminho);
        unlink($caminho);

        return $bytes;
    }

    /** @return array<string, string> */
    private function ler(string $bytes): array
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($caminho, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($caminho, \ZipArchive::RDONLY), 'o resultado é um zip');
        $arquivos = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nome = (string) $zip->getNameIndex($i);
            $arquivos[$nome] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($caminho);

        return $arquivos;
    }
}
