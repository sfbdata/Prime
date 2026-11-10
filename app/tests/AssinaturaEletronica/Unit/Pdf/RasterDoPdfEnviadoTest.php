<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\AssinaturaEletronica\Pdf\MedidasDoPdfEnviado;
use App\AssinaturaEletronica\Pdf\RasterDoPdfEnviado;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As páginas do PDF enviado em imagem: a A4 sai a 225 dpi, como sempre; a página maior que a caixa (1959 x 2604 px,
 * com a folga de 1,25: até 2448 x 3255) já sai do Ghostscript reduzida, numa passada só.
 */
#[CoversClass(RasterDoPdfEnviado::class)]
#[CoversClass(MedidasDoPdfEnviado::class)]
final class RasterDoPdfEnviadoTest extends TestCase
{
    /** Antes, a página de 200 x 200 pol ia inteira a 225 dpi (45000 x 45000 px) e só parava no tempo de 60 s. */
    private const RAPIDO_SEGUNDOS = 20.0;

    protected function setUp(): void
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript ausente.');
        }
    }

    #[TestDox('A4 comum sai a 225 dpi, do mesmo tamanho de antes')]
    public function testA4A225Dpi(): void
    {
        self::assertSame([1860, 2631], self::medidas(PdfDeCaixas::gerar('/MediaBox [0 0 595.28 841.89]'), 1));
    }

    #[TestDox('Página de 200 x 200 pol termina rápido e cabe na caixa (12 dpi, 2400 x 2400 px)')]
    public function testPaginaGiganteCabeNaCaixa(): void
    {
        $pdf = PdfDeCaixas::gerar('/MediaBox [0 0 14400 14400]');

        $inicio = microtime(true);
        $medidas = self::medidas($pdf, 1);

        self::assertLessThan(self::RAPIDO_SEGUNDOS, microtime(true) - $inicio);
        // floor(225 x 2448,75 / 45000) = 12 dpi; 200 pol x 12 = 2400 px.
        self::assertSame([2400, 2400], $medidas);
    }

    #[TestDox('A redução usa a página como ela sai: recortada, girada e na UserUnit de cada página')]
    public function testReducaoPorPagina(): void
    {
        $pdf = PdfDeCaixas::gerar(
            '/MediaBox [0 0 595.28 841.89]',
            // 14400 x 7200 pt deitada pelo Rotate: 100 x 200 pol de pé. A altura manda: floor(225 x 3255 / 45000) = 16 dpi.
            '/MediaBox [0 0 14400 7200] /Rotate 90',
            // CropBox de 300 x 400 pt dentro de uma MediaBox enorme, vezes 2: 600 x 800 pt a 225 dpi.
            '/MediaBox [0 0 14400 14400] /CropBox [0 0 300 400] /UserUnit 2',
        );
        $raster = new RasterDoPdfEnviado(GhostscriptDeTeste::binario(), $pdf);

        try {
            self::assertSame([1860, 2631], self::tamanho($raster->pagina(1)));
            self::assertSame([1600, 3200], self::tamanho($raster->pagina(2)));
            self::assertSame([1875, 2500], self::tamanho($raster->pagina(3)));
        } finally {
            $raster->liberar();
        }
    }

    #[TestDox('Página tão grande que nem a 2 dpi cabe falha logo, sem rasterizar')]
    public function testPaginaAbsurdaFalha(): void
    {
        $pdf = PdfDeCaixas::gerar('/MediaBox [0 0 10000000 10000000]');

        $inicio = microtime(true);
        try {
            self::medidas($pdf, 1);
            self::fail('A página absurda deveria falhar.');
        } catch (FalhaAoMontarPdf $e) {
            self::assertStringContainsString('grande demais', $e->getMessage());
        }
        self::assertLessThan(self::RAPIDO_SEGUNDOS, microtime(true) - $inicio);
    }

    /** @return array{int, int} */
    private static function medidas(string $pdf, int $pagina): array
    {
        $raster = new RasterDoPdfEnviado(GhostscriptDeTeste::binario(), $pdf);

        try {
            return self::tamanho($raster->pagina($pagina));
        } finally {
            $raster->liberar();
        }
    }

    /** @return array{int, int} */
    private static function tamanho(\GdImage $imagem): array
    {
        $tamanho = [imagesx($imagem), imagesy($imagem)];
        imagedestroy($imagem);

        return $tamanho;
    }
}
