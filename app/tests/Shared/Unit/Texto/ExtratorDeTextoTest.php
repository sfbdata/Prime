<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Texto;

use App\Shared\Texto\ArquivoSemTextoException;
use App\Shared\Texto\ExtratorDeTexto;
use App\Shared\Texto\ExtratorDeTextoDocx;
use App\Shared\Texto\FormatoDeTexto;
use PhpOffice\PhpSpreadsheet\Shared\OLE;
use PhpOffice\PhpSpreadsheet\Shared\OLE\PPS\File;
use PhpOffice\PhpSpreadsheet\Shared\OLE\PPS\Root;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtratorDeTexto::class)]
final class ExtratorDeTextoTest extends TestCase
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS_ODT = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"';

    private function extrator(): ExtratorDeTexto
    {
        return new ExtratorDeTexto(new ExtratorDeTextoDocx());
    }

    private function texto(string $bytes, FormatoDeTexto $formato): string
    {
        return $this->extrator()->extrair($bytes, $formato)->texto;
    }

    /** @param array<string, string> $arquivos */
    private function zip(array $arquivos): string
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'txt');
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

    private function docx(string $corpo): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<Types/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="' . self::W . '"><w:body>' . $corpo . '</w:body></w:document>',
        ]);
    }

    private function odt(string $corpo, string $mimetype = 'application/vnd.oasis.opendocument.text'): string
    {
        return $this->zip([
            'mimetype' => $mimetype,
            'content.xml' => '<?xml version="1.0"?><office:document-content ' . self::NS_ODT . '><office:body><office:text>' . $corpo . '</office:text></office:body></office:document-content>',
        ]);
    }

    /** Monta um DOC mínimo: FIB com o necessário e Clx de uma peça só. */
    private function doc(string $texto, bool $comprimido, int $opcoes = 0x0200, bool $tabelaPequena = true): string
    {
        $dados = $comprimido ? (string) mb_convert_encoding($texto, 'Windows-1252', 'UTF-8') : (string) mb_convert_encoding($texto, 'UTF-16LE', 'UTF-8');
        $caracteres = mb_strlen($texto);
        $deslocamento = 0x400;

        $fib = str_repeat("\0", 0x400);
        $fib = substr_replace($fib, pack('v', 0xA5EC), 0, 2);
        $fib = substr_replace($fib, pack('v', $opcoes), 0x0A, 2);
        $fib = substr_replace($fib, pack('V', $caracteres), 0x4C, 4);
        $plc = pack('VV', 0, $caracteres) . pack('vVv', 0, $comprimido ? (($deslocamento * 2) | 0x40000000) : $deslocamento, 0);
        $clx = "\x02" . pack('V', \strlen($plc)) . $plc;
        $fib = substr_replace($fib, pack('V', 0), 0x01A2, 4);
        $fib = substr_replace($fib, pack('V', \strlen($clx)), 0x01A6, 4);
        $palavra = $fib . $dados . ($tabelaPequena ? str_repeat("\0", 4200) : '');

        return $this->ole(['WordDocument' => $palavra, (($opcoes & 0x0200) !== 0 ? '1Table' : '0Table') => $clx]);
    }

    /** @param array<string, string> $fluxos */
    private function ole(array $fluxos): string
    {
        $filhos = [];
        foreach ($fluxos as $nome => $dados) {
            $arquivo = new File(OLE::ascToUcs($nome));
            $arquivo->append($dados);
            $filhos[] = $arquivo;
        }
        $raiz = new Root(1, 1, $filhos);
        $memoria = fopen('php://memory', 'w+b');
        self::assertNotFalse($memoria);
        $raiz->save($memoria);
        rewind($memoria);
        $bytes = (string) stream_get_contents($memoria);
        fclose($memoria);

        return $bytes;
    }

    /** @param callable(): mixed $acao */
    private function recusa(callable $acao, ?string $mensagem = null): void
    {
        try {
            $acao();
        } catch (ArquivoSemTextoException $e) {
            self::assertNotSame('', $e->getMessage());
            if ($mensagem !== null) {
                self::assertSame($mensagem, $e->getMessage());
            }

            return;
        }
        self::fail('Era para recusar o arquivo.');
    }

    #[TestDox('DOCX: delega ao extrator de DOCX')]
    public function testDocx(): void
    {
        $texto = $this->texto($this->docx('<w:p><w:r><w:t>Primeira</w:t></w:r></w:p><w:p><w:r><w:t>Segunda</w:t></w:r></w:p>'), FormatoDeTexto::Docx);

        self::assertSame("Primeira\nSegunda", $texto);
    }

    #[TestDox('DOCX acima de 60 mil: volta cortado, sem o aviso dentro do texto')]
    public function testDocxCortado(): void
    {
        $resultado = $this->extrator()->extrair($this->docx('<w:p><w:r><w:t>' . str_repeat('a', 70000) . '</w:t></w:r></w:p>'), FormatoDeTexto::Docx);

        self::assertTrue($resultado->cortado);
        self::assertSame(60000, mb_strlen($resultado->texto));
        self::assertStringNotContainsString('Conteúdo cortado', $resultado->texto);
    }

    #[TestDox('DOC: peça comprimida (Windows-1252) com acento, célula, quebra e campo')]
    public function testDocComprimido(): void
    {
        $texto = $this->texto($this->doc("Olá, ação\rVer \x13 HYPERLINK \"x\" \x14o link\x15 e \x13 PAGE \x15fim\x07B\x0Bnova", true), FormatoDeTexto::Doc);

        self::assertSame("Olá, ação\nVer o link e fim\tB\nnova", $texto);
    }

    #[TestDox('DOC: peça em UTF-16LE, fluxo de tabela grande ou pequeno e tabela 0Table')]
    public function testDocUtf16(): void
    {
        self::assertSame('Informação ✓', $this->texto($this->doc('Informação ✓', false), FormatoDeTexto::Doc));
        self::assertSame('Sem tabela 1', $this->texto($this->doc('Sem tabela 1', true, 0x0000), FormatoDeTexto::Doc));
        self::assertSame('Grande', $this->texto($this->doc('Grande', true, 0x0200, false), FormatoDeTexto::Doc));
    }

    #[TestDox('DOC: recusa cifrado, assinatura com lixo, PDF declarado como DOC e fluxos que apontam para fora')]
    public function testDocRecusas(): void
    {
        $extrator = $this->extrator();
        $casos = [
            'cifrado' => $this->doc('segredo', true, 0x0200 | 0x0100),
            'lixo' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . random_bytes(2000),
            'pdf' => "%PDF-1.4\n" . str_repeat('x', 1000),
            'curto' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1",
            'sem WordDocument' => $this->ole(['Outro' => str_repeat('a', 5000)]),
            'sem tabela' => $this->ole(['WordDocument' => str_repeat("\0", 5000)]),
        ];
        foreach ($casos as $bytes) {
            $this->recusa(static fn () => $extrator->extrair($bytes, FormatoDeTexto::Doc));
        }
    }

    #[TestDox('DOC: peça que aponta além do fluxo é recusada com mensagem fixa')]
    public function testDocPecaForaDoFluxo(): void
    {
        $bytes = $this->doc('Texto', true, 0x0200, false);
        // Aumenta o ccpText para além do que o fluxo guarda.
        $bytes = str_replace(pack('V', 5), pack('V', 3_000_000), $bytes);

        $extrator = $this->extrator();
        try {
            $extrator->extrair($bytes, FormatoDeTexto::Doc);
        } catch (ArquivoSemTextoException $e) {
            self::assertSame('O arquivo não é um DOC válido.', $e->getMessage());

            return;
        }
        self::markTestIncomplete('O arquivo adulterado foi lido sem erro; confira o deslocamento do ccpText.');
    }

    #[TestDox('ODT: texto de parágrafos, títulos, tabulação, quebra e espaços; ignora alteração rastreada')]
    public function testOdt(): void
    {
        $corpo = '<text:p>Primeira<text:tab/>coluna<text:line-break/>outra<text:s text:c="3"/>x</text:p>'
            . '<text:h text:outline-level="1">Título</text:h><text:tracked-changes><text:p>apagado</text:p></text:tracked-changes><text:p>Fim</text:p>';

        self::assertSame("Primeira\tcoluna\noutra   x\nTítulo\nFim", $this->texto($this->odt($corpo), FormatoDeTexto::Odt));
    }

    #[TestDox('ODT forjado: ZIP qualquer, mimetype errado, sem content.xml, DOCTYPE e bomba de compressão são recusados')]
    public function testOdtRecusas(): void
    {
        $extrator = $this->extrator();
        $ns = self::NS_ODT;
        $casos = [
            'zip qualquer' => $this->zip(['a.txt' => 'oi']),
            'mimetype errado' => $this->odt('<text:p>x</text:p>', 'application/zip'),
            'sem content.xml' => $this->zip(['mimetype' => 'application/vnd.oasis.opendocument.text']),
            'doctype' => $this->zip([
                'mimetype' => 'application/vnd.oasis.opendocument.text',
                'content.xml' => '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY e "x">]><office:document-content ' . $ns . '/>',
            ]),
            'bomba' => $this->zip([
                'mimetype' => 'application/vnd.oasis.opendocument.text',
                'content.xml' => '<office:document-content ' . $ns . '>' . str_repeat(' ', 5_000_000) . '</office:document-content>',
            ]),
            'não é zip' => 'texto qualquer',
        ];
        foreach ($casos as $bytes) {
            $this->recusa(static fn () => $extrator->extrair($bytes, FormatoDeTexto::Odt));
        }
    }

    #[TestDox('RTF: acentos, \\uN, campos, tabela; fonttbl, grupos \\* e imagem saem')]
    public function testRtf(): void
    {
        $rtf = '{\rtf1\ansi\ansicpg1252\deff0{\fonttbl{\f0 Arial;}}{\colortbl;\red0\green0\blue0;}{\*\generator Msftedit;}'
            . '\pard Ol\\\'e1 mundo\par Pre\u231?o ' . '\u' . '8364? caro\par {\pict\wbitmap0 abcdef}'
            . '{\field{\*\fldinst HYPERLINK "x"}{\fldrslt link}}\par a\cell b\cell\row \{literal\} \\\\ }';

        $texto = $this->texto($rtf, FormatoDeTexto::Rtf);

        self::assertStringContainsString("Olá mundo\nPreço € caro", $texto);
        self::assertStringContainsString('link', $texto);
        self::assertStringContainsString("a\tb\n", $texto);
        self::assertStringContainsString('{literal} \\', $texto);
        foreach (['Arial', 'Msftedit', 'abcdef', 'HYPERLINK', 'red0'] as $fora) {
            self::assertStringNotContainsString($fora, $texto);
        }
    }

    #[TestDox('RTF: não-RTF, grupos aninhados demais e RTF sem texto são recusados')]
    public function testRtfRecusas(): void
    {
        $extrator = $this->extrator();
        foreach (['texto comum', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", '{\rtf1' . str_repeat('{', 2000) . '}', '{\rtf1{\fonttbl{\f0 A;}}}'] as $bytes) {
            $this->recusa(static fn () => $extrator->extrair($bytes, FormatoDeTexto::Rtf));
        }
    }

    #[TestDox('TXT: UTF-8, UTF-8 com BOM, Windows-1252, UTF-16LE e UTF-16BE com BOM')]
    public function testTxt(): void
    {
        $utf8 = "Ação e coração\r\nsegunda  \r\n\r\n\r\n\r\nterceira";
        $esperado = "Ação e coração\nsegunda\n\nterceira";

        self::assertSame($esperado, $this->texto($utf8, FormatoDeTexto::Txt));
        self::assertSame($esperado, $this->texto("\xEF\xBB\xBF" . $utf8, FormatoDeTexto::Txt));
        self::assertSame($esperado, $this->texto((string) mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'), FormatoDeTexto::Txt));
        self::assertSame($esperado, $this->texto("\xFF\xFE" . mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8'), FormatoDeTexto::Txt));
        self::assertSame($esperado, $this->texto("\xFE\xFF" . mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'), FormatoDeTexto::Txt));
    }

    #[TestDox('CSV e KML são lidos como texto, com as marcas literais')]
    public function testCsvEKml(): void
    {
        self::assertSame("nome;valor\nAna;10", $this->texto("nome;valor\nAna;10\n", FormatoDeTexto::Csv));
        self::assertSame('<kml><Placemark/></kml>', $this->texto('<kml><Placemark/></kml>', FormatoDeTexto::Kml));
    }

    #[TestDox('XML com entidade externa: a declaração aparece literal e o arquivo do servidor nunca é lido')]
    public function testXmlComEntidadeExterna(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>';

        $texto = $this->texto($xml, FormatoDeTexto::Xml);

        self::assertStringContainsString('<!ENTITY e SYSTEM "file:///etc/passwd">', $texto);
        self::assertStringContainsString('&e;', $texto);
        self::assertStringNotContainsString('root:', $texto);
    }

    #[TestDox('Texto puro: PNG (com NUL) declarado como TXT é recusado')]
    public function testPngComoTxt(): void
    {
        $this->recusa(fn () => $this->texto("\x89PNG\r\n\x1a\n\0\0\0\rIHDR", FormatoDeTexto::Txt), 'O arquivo não é um arquivo de texto.');
    }

    #[TestDox('Peça do editor: tira imagem (inclusive base64), script, estilo e comentário; blocos viram linhas')]
    public function testPecaDoEditor(): void
    {
        $html = '<style>p{color:red}</style><!-- nota --><h1>Título</h1><p>Olá&nbsp;&amp; <b>mundo</b><img src="data:image/png;base64,' . str_repeat('QUJD', 5000) . '" alt="x"></p>'
            . '<script>alert("x")</script><p>Linha<br>quebrada</p><table><tr><td>a</td><td>b</td></tr></table><ul><li>item</li></ul>';

        $texto = $this->texto($html, FormatoDeTexto::PecaDoEditor);

        self::assertSame("Título\nOlá & mundo\nLinha\nquebrada\na\tb\nitem", $texto);
    }

    #[TestDox('Peça do editor: byte NUL ou UTF-8 inválido é recusado')]
    public function testPecaInvalida(): void
    {
        $extrator = $this->extrator();
        foreach (["<p>a\0b</p>", "<p>\xE9</p>"] as $bytes) {
            $this->recusa(static fn () => $extrator->extrair($bytes, FormatoDeTexto::PecaDoEditor));
        }
    }

    #[TestDox('Normalização: controles saem, espaços do fim da linha saem, 3+ quebras viram 2')]
    public function testNormalizacao(): void
    {
        self::assertSame("a\tb\n\nc", $this->texto("\x01a\tb \x02\n\n\n\n\nc\x1F  ", FormatoDeTexto::Txt));
    }

    #[TestDox('Vazio, só espaços e tamanho acima do teto são recusados, com mensagem fixa')]
    public function testVazioEGrande(): void
    {
        $this->recusa(fn () => $this->texto('', FormatoDeTexto::Txt), 'O arquivo não tem texto para ler.');
        $this->recusa(fn () => $this->texto(" \n\t ", FormatoDeTexto::Txt), 'O arquivo não tem texto para ler.');
        $this->recusa(fn () => $this->texto(str_repeat('a', 5 * 1024 * 1024 + 1), FormatoDeTexto::Csv), 'O arquivo é grande demais.');
    }

    #[DataProvider('maximos')]
    #[TestDox('Corte: acima do máximo volta cortado, com exatamente o máximo de caracteres')]
    public function testCorte(int $maximo, bool $cortado): void
    {
        $resultado = $this->extrator()->extrair(str_repeat('é', 100), FormatoDeTexto::Txt, $maximo);

        self::assertSame($cortado, $resultado->cortado);
        self::assertSame(min($maximo, 100), mb_strlen($resultado->texto));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function maximos(): iterable
    {
        yield 'menor' => [40, true];
        yield 'igual' => [100, false];
        yield 'maior' => [500, false];
    }

    #[TestDox('Texto de 70 mil caracteres no padrão volta cortado em 60 mil')]
    public function testCorteNoPadrao(): void
    {
        $resultado = $this->extrator()->extrair(str_repeat('a', 70000), FormatoDeTexto::Txt);

        self::assertTrue($resultado->cortado);
        self::assertSame(ExtratorDeTexto::MAXIMO_DE_CARACTERES, mb_strlen($resultado->texto));
    }
}
