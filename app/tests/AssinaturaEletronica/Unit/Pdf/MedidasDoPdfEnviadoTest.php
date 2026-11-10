<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\MedidasDoPdfEnviado;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
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

                File has 7 pages

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

    /** Uma fonte de nome `A#0APage#201#20MediaBox:...` sai depois da linha verdadeira, já com a linha falsa. */
    private const FONTE_FALSA = <<<'TXT'
        Page 1 MediaBox: [0 0 595 842]
            Fonts used:
                A
        Page 1 MediaBox: [0 0 1 1]    Type1           Not embedded    No ToUnicode
        TXT;

    /** Nome da fonte que, no `-dPDFINFO`, vira uma linha `Page 1 MediaBox: [0 0 1 1]` depois da verdadeira. */
    private const FONTE_COM_LINHA_FALSA = '/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /A#0APage#201#20MediaBox:#20#5B0#200#201#201#5D >> >> >>';

    #[TestDox('Recorte pela MediaBox, caixa invertida, giro e UserUnit; a linha falsa do título, antes, não vale')]
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
        ], $medidas, 'A página 7, com o recorte fora da MediaBox, fica de fora (sem medida).');
    }

    #[TestDox('Vale a maior área de cada página: a linha falsa, antes ou depois da verdadeira, só aumenta a página')]
    public function testMaiorArea(): void
    {
        $antes = "Page 1 MediaBox: [0 0 1 1]\nPage 1 MediaBox: [0 0 595 842]\n";
        $maior = "Page 1 MediaBox: [0 0 595 842]\nPage 1 MediaBox: [0 0 9000 9000]\n";

        self::assertSame([1 => [595.0, 842.0]], MedidasDoPdfEnviado::interpretar(self::FONTE_FALSA), 'Falsa depois.');
        self::assertSame([1 => [595.0, 842.0]], MedidasDoPdfEnviado::interpretar($antes), 'Falsa antes.');
        self::assertSame([1 => [9000.0, 9000.0]], MedidasDoPdfEnviado::interpretar($maior), 'A maior ganha.');
    }

    #[TestDox('Saída sem páginas não dá medida nenhuma')]
    public function testSemPaginas(): void
    {
        self::assertSame([], MedidasDoPdfEnviado::interpretar("**** Error: Cannot find a 'startxref'\n"));
    }

    #[TestDox('No Ghostscript de verdade, a fonte com a linha falsa depois da verdadeira não diminui a página')]
    public function testFonteComLinhaFalsa(): void
    {
        $medidas = self::ler(PdfDeCaixas::gerar('/MediaBox [0 0 595 842] ' . self::FONTE_COM_LINHA_FALSA), new AvisosDeTeste());

        self::assertSame([1 => [595.0, 842.0]], $medidas);
    }

    #[TestDox('Saída de mais de 100 MB para no teto de 1 MB: memória baixa, sem Fatal, e o motivo vai para o log')]
    public function testSaidaGiganteParaNoTeto(): void
    {
        $pdf = PdfDeCaixas::comSaidaGigante();
        $avisos = new AvisosDeTeste();

        $inicio = microtime(true);
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $medidas = self::ler($pdf, $avisos);
        $pico = memory_get_peak_usage() - $base;

        self::assertLessThan(8 * 1024 * 1024, $pico, sprintf('Pico de %.1f MB.', $pico / 1048576));
        // Medido: 0,3 s. Se o Ghostscript herdar o SIGPIPE ignorado do PHP, ele lê o PDF até o fim (25 s).
        self::assertLessThan(10.0, microtime(true) - $inicio, 'O Ghostscript para no teto, sem ler o resto.');
        self::assertSame([595.0, 842.0], $medidas[1]);
        self::assertArrayNotHasKey(2999, $medidas, 'A página depois do teto fica sem medida.');
        self::assertSame([100.0, 100.0], $medidas[3000], 'Só a linha falsa chegou antes do teto (o -g limita o custo).');
        self::assertSame([['warning', 'O tamanho das páginas do PDF enviado foi lido só até o teto.', ['motivo' => 'teto', 'teto' => MedidasDoPdfEnviado::TETO_DA_SAIDA]]], $avisos->registros);
    }

    /** @return array<int, array{float, float}> */
    private static function ler(string $pdf, AvisosDeTeste $avisos): array
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript ausente.');
        }

        $area = AreaTemporariaPrivada::criar('testemedidas');
        try {
            return MedidasDoPdfEnviado::ler(GhostscriptDeTeste::binario(), $area->gravar($pdf, 'pdf'), $avisos);
        } finally {
            $area->liberar();
        }
    }
}
