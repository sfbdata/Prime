<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Tenant\Exception\DadosDoEscritorioInvalidos;
use App\Tenant\Service\PreparadorDeLogoDoEscritorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreparadorDeLogoDoEscritorio::class)]
final class PreparadorDeLogoDoEscritorioTest extends TestCase
{
    private function imagem(string $tipo, int $largura, int $altura): string
    {
        $img = imagecreatetruecolor($largura, $altura);
        ob_start();
        match ($tipo) {
            'png' => imagepng($img),
            'jpeg' => imagejpeg($img),
            'gif' => imagegif($img),
        };

        return (string) ob_get_clean();
    }

    #[TestDox('PNG grande é reduzido a 320 px no maior lado, mantendo a proporção, e sai PNG')]
    public function testReduzPng(): void
    {
        $logo = (new PreparadorDeLogoDoEscritorio())->preparar($this->imagem('png', 1000, 500));

        self::assertSame([320, 160, 'png', 'image/png'], [$logo->largura, $logo->altura, $logo->extensao, $logo->mime]);
        self::assertSame(\IMAGETYPE_PNG, getimagesizefromstring($logo->bytes)[2] ?? null);
    }

    #[TestDox('JPEG sai JPEG; imagem pequena não é ampliada; GIF vira PNG')]
    public function testJpegPequenaEGif(): void
    {
        $preparador = new PreparadorDeLogoDoEscritorio();

        $jpeg = $preparador->preparar($this->imagem('jpeg', 100, 80));
        self::assertSame([100, 80, 'jpg'], [$jpeg->largura, $jpeg->altura, $jpeg->extensao]);
        self::assertSame('png', $preparador->preparar($this->imagem('gif', 50, 50))->extensao);
    }

    #[TestDox('texto, PHP disfarçado, vazio e imagem corrompida são recusados')]
    public function testTipoInvalido(): void
    {
        $preparador = new PreparadorDeLogoDoEscritorio();

        foreach (['<?php echo 1;', '', 'GIF89a' . 'lixo', substr($this->imagem('png', 10, 10), 0, 30)] as $bytes) {
            try {
                $preparador->preparar($bytes);
                self::fail('deveria recusar');
            } catch (DadosDoEscritorioInvalidos $e) {
                self::assertSame(PreparadorDeLogoDoEscritorio::TIPO_RECUSADO, $e->getMessage());
            }
        }
    }

    #[TestDox('passa de 2 MB: recusa antes de decodificar')]
    public function testGrandeDemais(): void
    {
        $this->expectException(DadosDoEscritorioInvalidos::class);
        $this->expectExceptionMessage(PreparadorDeLogoDoEscritorio::GRANDE_DEMAIS);

        (new PreparadorDeLogoDoEscritorio())->preparar(str_repeat('a', PreparadorDeLogoDoEscritorio::LIMITE_EM_BYTES + 1));
    }
}
