<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\DetectorDeAssinaturaDigital;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O detector do selo "Assinado" com bytes sintéticos: os três marcadores juntos (tipo, `/ByteRange`,
 * `/SubFilter` conhecido), a extensão `.p7s`/`.p7m` e a leitura das duas pontas de um arquivo grande.
 */
#[CoversClass(DetectorDeAssinaturaDigital::class)]
final class DetectorDeAssinaturaDigitalTest extends TestCase
{
    private DetectorDeAssinaturaDigital $detector;

    /** @var list<string> */
    private array $arquivos = [];

    protected function setUp(): void
    {
        $this->detector = new DetectorDeAssinaturaDigital();
    }

    protected function tearDown(): void
    {
        foreach ($this->arquivos as $caminho) {
            @unlink($caminho);
        }
    }

    public static function dicionario(string $tipo = '/Type /Sig', string $subFilter = '/adbe.pkcs7.detached'): string
    {
        return "5 0 obj\n<< {$tipo} /Filter /Adobe.PPKLite /SubFilter {$subFilter} /ByteRange [0 840 17224 1200] /Contents <3082> /M (D:20261020120000-03'00') >>\nendobj\n";
    }

    public static function pdf(string $corpo): string
    {
        return "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\n" . $corpo . "trailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /** @return iterable<string, array{string}> */
    public static function subFiltrosConhecidos(): iterable
    {
        yield 'PAdES básico (adbe.pkcs7.detached)' => ['/adbe.pkcs7.detached'];
        yield 'PAdES ICP-Brasil/gov.br (ETSI.CAdES.detached)' => ['/ETSI.CAdES.detached'];
        yield 'legado (adbe.pkcs7.sha1)' => ['/adbe.pkcs7.sha1'];
        yield 'carimbo do tempo (ETSI.RFC3161)' => ['/ETSI.RFC3161'];
    }

    #[DataProvider('subFiltrosConhecidos')]
    #[TestDox('PDF com dicionário de assinatura e SubFilter $subFilter é assinado')]
    public function testSubFiltrosConhecidos(string $subFilter): void
    {
        self::assertTrue($this->detector->pdfAssinado(self::pdf(self::dicionario('/Type /Sig', $subFilter))));
    }

    #[TestDox('o tipo vale como /Type /Sig, /Type/Sig ou /FT /Sig')]
    public function testVariacoesDoTipo(): void
    {
        self::assertTrue($this->detector->pdfAssinado(self::pdf(self::dicionario('/Type/Sig'))));
        self::assertTrue($this->detector->pdfAssinado(self::pdf(self::dicionario('/FT /Sig'))));
        self::assertTrue($this->detector->pdfAssinado(self::pdf(self::dicionario("/Type\n/Sig"))));
    }

    #[TestDox('PDF comum, sem nenhum marcador, não é assinado')]
    public function testPdfComum(): void
    {
        self::assertFalse($this->detector->pdfAssinado(self::pdf('')));
    }

    /** @return iterable<string, array{string}> */
    public static function faltaUmMarcador(): iterable
    {
        $completo = self::dicionario();

        yield 'sem /ByteRange (campo de assinatura vazio)' => [str_replace('/ByteRange [0 840 17224 1200]', '', $completo)];
        yield 'sem /SubFilter' => [str_replace('/SubFilter /adbe.pkcs7.detached', '', $completo)];
        yield 'SubFilter desconhecido' => [str_replace('/adbe.pkcs7.detached', '/adbe.x509.rsa_sha1', $completo)];
        yield 'sem o tipo /Sig' => [str_replace('/Type /Sig', '/Type /Annot', $completo)];
        yield 'tipo /SigRef não é /Sig' => [str_replace('/Type /Sig', '/Type /SigRef', $completo)];
        yield 'SubFilter com sufixo não é o conhecido' => [str_replace('/adbe.pkcs7.detached', '/adbe.pkcs7.detachedX', $completo)];
    }

    #[DataProvider('faltaUmMarcador')]
    #[TestDox('sem os três marcadores juntos, não é assinado: $_dataName')]
    public function testFaltaUmMarcador(string $dicionario): void
    {
        self::assertFalse($this->detector->pdfAssinado(self::pdf($dicionario)));
    }

    #[TestDox('.p7s e .p7m são assinados pela extensão, sem olhar o conteúdo')]
    public function testExtensoesAssinadas(): void
    {
        $caminho = $this->arquivo('qualquer coisa');

        self::assertTrue($this->detector->arquivoAssinado($caminho, 'contrato.pdf.p7s', 'application/pkcs7-signature'));
        self::assertTrue($this->detector->arquivoAssinado($caminho, 'CONTRATO.P7M', 'application/octet-stream'));
        self::assertFalse($this->detector->arquivoAssinado($caminho, 'contrato.txt', 'text/plain'));
    }

    #[TestDox('arquivo PDF local com assinatura é lido e reconhecido; outro MIME não é inspecionado')]
    public function testArquivoLocalPdf(): void
    {
        $assinado = $this->arquivo(self::pdf(self::dicionario('/Type /Sig', '/ETSI.CAdES.detached')));

        self::assertTrue($this->detector->arquivoAssinado($assinado, 'contrato.pdf', 'application/pdf'));
        self::assertFalse($this->detector->arquivoAssinado($assinado, 'contrato.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        self::assertFalse($this->detector->arquivoAssinado($this->arquivo(self::pdf('')), 'contrato.pdf', 'application/pdf'));
    }

    #[TestDox('PDF grande: a assinatura incremental no FIM é achada; no meio, fora das pontas, não é lida')]
    public function testArquivoGrandeLeSoAsPontas(): void
    {
        $limite  = DetectorDeAssinaturaDigital::LIMITE_POR_PONTA;
        $recheio = str_repeat('x', 2 * $limite);

        $noFim = $this->arquivo(self::pdf($recheio) . self::dicionario() . "%%EOF\n");
        self::assertTrue($this->detector->arquivoAssinado($noFim, 'grande.pdf', 'application/pdf'));

        $noMeio = $this->arquivo(self::pdf($recheio . self::dicionario() . $recheio));
        self::assertFalse($this->detector->arquivoAssinado($noMeio, 'grande.pdf', 'application/pdf'));
    }

    #[TestDox('arquivo ilegível responde false, sem lançar')]
    public function testArquivoInexistente(): void
    {
        self::assertFalse($this->detector->arquivoAssinado(sys_get_temp_dir() . '/nao-existe-' . bin2hex(random_bytes(4)) . '.pdf', 'x.pdf', 'application/pdf'));
    }

    #[TestDox('stream do armazenamento: com seek (pontas) e sem seek (sequencial, janela do fim)')]
    public function testStream(): void
    {
        $limite  = DetectorDeAssinaturaDigital::LIMITE_POR_PONTA;
        $recheio = str_repeat('x', 2 * $limite);

        foreach ([true, false] as $comSeek) {
            $rotulo = $comSeek ? 'com seek' : 'sem seek';
            self::assertTrue($this->detector->streamAssinado($this->stream(self::pdf(self::dicionario()), $comSeek)), $rotulo . ': pequeno');
            self::assertFalse($this->detector->streamAssinado($this->stream(self::pdf(''), $comSeek)), $rotulo . ': comum');
            self::assertTrue($this->detector->streamAssinado($this->stream(self::pdf($recheio) . self::dicionario(), $comSeek)), $rotulo . ': no fim');
            self::assertTrue($this->detector->streamAssinado($this->stream(self::dicionario() . $recheio . self::pdf(''), $comSeek)), $rotulo . ': no começo');
            self::assertFalse($this->detector->streamAssinado($this->stream(self::pdf($recheio . self::dicionario() . $recheio), $comSeek)), $rotulo . ': só no meio');
        }
    }

    /** @return resource */
    private function stream(string $conteudo, bool $comSeek): mixed
    {
        if ($comSeek) {
            $stream = fopen('php://temp', 'w+b');
            self::assertIsResource($stream);
            fwrite($stream, $conteudo);
            rewind($stream);

            return $stream;
        }

        if (!\in_array('selo-sem-seek', stream_get_wrappers(), true)) {
            stream_wrapper_register('selo-sem-seek', StreamSemSeek::class);
        }
        StreamSemSeek::$conteudo = $conteudo;
        $stream = fopen('selo-sem-seek://x', 'rb');
        self::assertIsResource($stream);
        // Sem tamanho no `fstat` (o `stream_stat` vazio dá 0), o detector não tenta seek: lê em sequência.
        self::assertSame(0, (fstat($stream) ?: [])['size'] ?? 0);

        return $stream;
    }

    private function arquivo(string $conteudo): string
    {
        $caminho = sys_get_temp_dir() . '/detector-assinatura-' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);
        $this->arquivos[] = $caminho;

        return $caminho;
    }
}

/** Stream sem `stream_seek` e sem tamanho, como o de um armazenamento remoto. */
final class StreamSemSeek
{
    public static string $conteudo = '';

    /** @var resource|null */
    public $context;

    private int $posicao = 0;

    public function stream_open(string $caminho, string $modo, int $opcoes, ?string &$aberto): bool
    {
        $this->posicao = 0;

        return true;
    }

    public function stream_read(int $quantos): string
    {
        $bloco          = substr(self::$conteudo, $this->posicao, $quantos);
        $this->posicao += \strlen($bloco);

        return $bloco;
    }

    public function stream_eof(): bool
    {
        return $this->posicao >= \strlen(self::$conteudo);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
