<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Service\CompressorArquivo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(CompressorArquivo::class)]
final class CompressorArquivoTest extends TestCase
{
    private const GS_BIN = '/usr/bin/gs';

    private string $dir;
    private CompressorArquivo $compressor;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/compressor_test_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->compressor = new CompressorArquivo(new NullLogger(), self::GS_BIN);
    }

    protected function tearDown(): void
    {
        // GLOB_BRACE para também capturar os temporários dotfile (.compress_*).
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $arquivo) {
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }
        @rmdir($this->dir);
    }

    public function testJpegRuidosoEReduzido(): void
    {
        $caminho = $this->dir . '/foto.jpg';
        $imagem  = $this->imagemRuidosa(500, 500);
        imagejpeg($imagem, $caminho, 100); // fonte em qualidade máxima → grande
        imagedestroy($imagem);

        $original = (int) filesize($caminho);
        $resultado = $this->compressor->comprimir($caminho, 'image/jpeg');

        self::assertTrue($resultado->comprimido);
        self::assertSame($original, $resultado->tamanhoOriginal);
        self::assertLessThan($original, $resultado->tamanhoFinal);
        self::assertSame($resultado->tamanhoFinal, (int) filesize($caminho));

        $info = getimagesize($caminho);
        self::assertNotFalse($info);
        self::assertSame('image/jpeg', $info['mime']);
    }

    public function testPngEReduzidoEContinuaValido(): void
    {
        $caminho = $this->dir . '/imagem.png';
        $imagem  = $this->imagemGradiente(300, 300); // suave → comprime bem
        imagepng($imagem, $caminho, 0); // fonte sem compressão → grande
        imagedestroy($imagem);

        $original = (int) filesize($caminho);
        $resultado = $this->compressor->comprimir($caminho, 'image/png');

        self::assertTrue($resultado->comprimido);
        self::assertLessThan($original, $resultado->tamanhoFinal);

        $info = getimagesize($caminho);
        self::assertNotFalse($info);
        self::assertSame('image/png', $info['mime']);
    }

    public function testPngPreservaTransparencia(): void
    {
        $caminho = $this->dir . '/transparente.png';

        $origem = imagecreatetruecolor(120, 120);
        imagesavealpha($origem, true);
        $transparente = imagecolorallocatealpha($origem, 0, 0, 0, 127);
        imagefill($origem, 0, 0, $transparente);
        // Um quadrado opaco no meio para garantir conteúdo + alpha misto.
        $vermelho = imagecolorallocate($origem, 255, 0, 0);
        imagefilledrectangle($origem, 30, 30, 90, 90, $vermelho);
        imagepng($origem, $caminho, 0);
        imagedestroy($origem);

        $this->compressor->comprimir($caminho, 'image/png');

        $resultado = imagecreatefrompng($caminho);
        self::assertInstanceOf(\GdImage::class, $resultado);
        // Canto (0,0) deve continuar totalmente transparente após o re-encode.
        $cor = imagecolorat($resultado, 0, 0);
        $alpha = ($cor >> 24) & 0x7F;
        imagedestroy($resultado);

        self::assertSame(127, $alpha, 'A transparência do PNG deve ser preservada.');
    }

    public function testMimeNaoSuportadoEUmNoOp(): void
    {
        $caminho = $this->dir . '/nota.txt';
        $conteudo = str_repeat('conteudo de texto sem compressao ', 200);
        file_put_contents($caminho, $conteudo);
        $original = (int) filesize($caminho);

        $resultado = $this->compressor->comprimir($caminho, 'text/plain');

        self::assertFalse($resultado->comprimido);
        self::assertSame($original, $resultado->tamanhoFinal);
        self::assertSame($conteudo, file_get_contents($caminho));
    }

    public function testArquivoInexistenteNaoQuebra(): void
    {
        $resultado = $this->compressor->comprimir($this->dir . '/nao-existe.jpg', 'image/jpeg');

        self::assertFalse($resultado->comprimido);
        self::assertSame(0, $resultado->tamanhoOriginal);
    }

    public function testPdfAssinadoEDetectado(): void
    {
        $assinado = $this->dir . '/assinado.pdf';
        file_put_contents($assinado, "%PDF-1.4\n/Type /Sig /ByteRange [0 100 200 300]\n%%EOF");
        self::assertTrue($this->compressor->pdfEstaAssinado($assinado));

        $comum = $this->dir . '/comum.pdf';
        file_put_contents($comum, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");
        self::assertFalse($this->compressor->pdfEstaAssinado($comum));
    }

    public function testPdfNaoEhCorrompidoQuandoComprime(): void
    {
        if (!is_executable(self::GS_BIN)) {
            self::markTestSkipped('Ghostscript indisponível neste ambiente.');
        }

        $caminho = $this->dir . '/doc.pdf';
        file_put_contents(
            $caminho,
            "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\n%%EOF",
        );

        $resultado = $this->compressor->comprimir($caminho, 'application/pdf');

        // Independentemente de reduzir ou não, o arquivo precisa continuar sendo um PDF válido.
        self::assertGreaterThan(0, $resultado->tamanhoFinal);
        self::assertStringStartsWith('%PDF', (string) file_get_contents($caminho));
    }

    private function imagemRuidosa(int $largura, int $altura): \GdImage
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        for ($x = 0; $x < $largura; $x++) {
            for ($y = 0; $y < $altura; $y++) {
                imagesetpixel($imagem, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }

        return $imagem;
    }

    private function imagemGradiente(int $largura, int $altura): \GdImage
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        for ($x = 0; $x < $largura; $x++) {
            for ($y = 0; $y < $altura; $y++) {
                $cor = (($x % 256) << 16) | (($y % 256) << 8);
                imagesetpixel($imagem, $x, $y, $cor);
            }
        }

        return $imagem;
    }
}
