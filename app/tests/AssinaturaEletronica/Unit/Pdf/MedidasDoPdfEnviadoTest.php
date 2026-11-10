<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\MedidasDoPdfEnviado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A leitura do `-dPDFINFO`: as linhas abaixo são a saída do Ghostscript 10.05 do contêiner para PDFs de teste (as
 * medidas conferidas contra a imagem que o próprio Ghostscript desenha com `-dUseCropBox`).
 */
#[CoversClass(MedidasDoPdfEnviado::class)]
final class MedidasDoPdfEnviadoTest extends TestCase
{
    private const SAIDA = <<<'TXT'

                File has 6 pages

        Title: Teste
        Page 1 MediaBox: [0 0 1 1]
        Producer: GPL Ghostscript 10.05.1

        Page 1 MediaBox: [0 0 612 792]    Rotate = 180
            Fonts used:
                Helvetica                       Type1           Not embedded    Has ToUnicode

        Page 2 MediaBox: [0 0 612 792] CropBox: [-100 -100 300 400]    Rotate = 180
        Page 3 MediaBox: [600 800 0 0]    Rotate = 180
        Page 4 MediaBox: [0 0 600 800] CropBox: [50 50 350 650]    Rotate = 270
        Page 5 UserUnit: 2.000000  MediaBox: [0 0 600 800] CropBox: [0 0 300 400]    Rotate = 180
        Page 6 MediaBox: [-300 -400 300 400]    Rotate = -90
        Page 7 MediaBox: [0 0 600 800] CropBox: [700 900 800 1000]
        TXT;

    #[TestDox('Recorte pela MediaBox, caixa invertida, giro, UserUnit e a linha falsa do título')]
    public function testInterpretar(): void
    {
        $medidas = MedidasDoPdfEnviado::interpretar(self::SAIDA);

        self::assertSame([
            1 => [612.0, 792.0],
            2 => [300.0, 400.0],
            3 => [600.0, 800.0],
            4 => [600.0, 300.0],
            5 => [600.0, 800.0],
            6 => [800.0, 600.0],
        ], $medidas, 'A página 7, com o recorte fora da MediaBox, fica de fora (vai a 225 dpi).');
    }

    #[TestDox('Saída sem páginas não dá medida nenhuma')]
    public function testSemPaginas(): void
    {
        self::assertSame([], MedidasDoPdfEnviado::interpretar("**** Error: Cannot find a 'startxref'\n"));
    }
}
