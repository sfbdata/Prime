<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\MedidasDoPdfEnviado;
use App\AssinaturaEletronica\Service\ValidadorDeBlocos;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A leitura das medidas pelo nosso programa PostScript: só as linhas com a marca da leitura valem, só números, e a
 * conta da página (recorte, giro, UserUnit) é a do desenho do Ghostscript com `-dUseCropBox`.
 */
#[CoversClass(MedidasDoPdfEnviado::class)]
final class MedidasDoPdfEnviadoTest extends TestCase
{
    private const MARCA = '0123456789abcdef0123456789abcdef';
    private const TETO = ValidadorDeBlocos::PAGINAS_SEM_CONTAGEM;

    #[TestDox('Recorte pela MediaBox, caixa invertida, giro como o Ghostscript gira e UserUnit')]
    public function testInterpretar(): void
    {
        $saida = self::linhas(
            '1 0 0 612 792 0 0 612 792 180 1',
            '2 0 0 612 792 -100 -100 300 400 180 1',
            '3 600 800 0 0 600 800 0 0 180 1',
            '4 0 0 600 800 50 50 350 650 270 1',
            '5 0 0 600 800 0 0 300 400 180 2.0',
            '6 -300 -400 300 400 -300 -400 300 400 -90 1',
            '7 0 0 600 800 700 900 800 1000 0 1',
            '8 0 0 600 800 0 0 600 800 100 1',
            '9 0 0 600 800 0 0 600 800 45 1',
            '10 0 0 595.5 842.25 0 0 595.5 842.25 -181 1',
            '11 0 0 1.0e+3 1e3 0 0 1000 1000 0 .5',
        );

        self::assertSame([
            1 => [612.0, 792.0],
            2 => [300.0, 400.0],
            3 => [600.0, 800.0],
            4 => [600.0, 300.0],
            5 => [600.0, 800.0],
            6 => [800.0, 600.0],
            8 => [800.0, 600.0],
            9 => [600.0, 800.0],
            10 => [595.5, 842.25],
            11 => [500.0, 500.0],
        ], MedidasDoPdfEnviado::interpretar($saida, self::MARCA), 'A página 7, com o recorte fora da MediaBox, fica sem medida.');
    }

    #[TestDox('Linha sem a marca, com outra marca, com algo que não é número, com campo a mais ou a menos, ou cortada, não vale')]
    public function testLinhasRecusadas(): void
    {
        $saida = "1 0 0 1 1 0 0 1 1 0 1\n"
            . 'ffffffffffffffffffffffffffffffff 1 0 0 1 1 0 0 1 1 0 1' . "\n"
            . 'Aviso: ' . self::MARCA . " 1 0 0 1 1 0 0 1 1 0 1\n"
            . self::linhas(
                '2 0 0 x 792 0 0 612 792 0 1',
                '3 0 0 612 792 0 0 612 792 0',
                '4 0 0 612 792 0 0 612 792 0 1 1',
                '5 0 0 612 792 0 0 612 792 0 1',
                '5 0 0 9000 9000 0 0 9000 9000 0 1',
            )
            . self::MARCA . ' 6 0 0 612 792 0 0 612 792 0 1';

        self::assertSame([5 => [612.0, 792.0]], MedidasDoPdfEnviado::interpretar($saida, self::MARCA), 'A página repetida fica com a primeira linha; a 6, sem quebra no fim, fica de fora.');
    }

    #[TestDox('No Ghostscript de verdade, as medidas batem com as caixas e a leitura termina bem')]
    public function testGhostscriptDeVerdade(): void
    {
        $pdf = PdfDeCaixas::gerar(
            '/MediaBox [0 0 612 792]',
            '/MediaBox [0 0 600 800] /CropBox [-100 -100 300 400] /Rotate 90 /UserUnit 2',
            '/MediaBox [0 0 595.5 842.25] /Rotate -90',
        );

        self::assertSame([[1 => [612.0, 792.0], 2 => [800.0, 600.0], 3 => [842.25, 595.5]], null], self::ler($pdf, 1, self::TETO));
        self::assertSame([[2 => [800.0, 600.0]], null], self::ler($pdf, 2, 2), 'Só a página pedida.');
    }

    #[TestDox('Linha falsa no nome de uma fonte e no título, com quebra de linha, não muda a medida')]
    public function testInjecaoPeloPdf(): void
    {
        $titulo = "Contrato\n" . self::MARCA . " 1 0 0 1 1 0 0 1 1 0 1\nPage 1 MediaBox: [0 0 1 1]\n";
        $pdf = PdfDeCaixas::comTitulo($titulo, '/MediaBox [0 0 595 842] ' . PdfDeCaixas::FONTE_COM_LINHA_FALSA);

        self::assertSame([[1 => [595.0, 842.0]], null], self::ler($pdf, 1, self::TETO));
    }

    #[TestDox('PDF de 120 páginas com 150 fontes cada: todas as páginas são medidas')]
    public function testMuitasFontes(): void
    {
        [$medidas, $motivo] = self::ler(PdfDeCaixas::comFontes(120, 150), 1, self::TETO);

        self::assertNull($motivo);
        self::assertSame(array_fill(1, 120, [595.0, 842.0]), $medidas);
    }

    #[TestDox('PDF hostil de 3000 páginas: mede até o teto de páginas, com pouca memória e rápido; a de depois do teto é medida sozinha')]
    public function testPdfHostil(): void
    {
        $pdf = PdfDeCaixas::comSaidaGigante();

        $inicio = microtime(true);
        memory_reset_peak_usage();
        $base = memory_get_usage();
        [$medidas, $motivo] = self::ler($pdf, 1, self::TETO);
        $pico = memory_get_peak_usage() - $base;
        $tempo = microtime(true) - $inicio;

        self::assertNull($motivo);
        self::assertCount(self::TETO, $medidas);
        self::assertSame([595.0, 842.0], $medidas[self::TETO]);
        self::assertArrayNotHasKey(self::TETO + 1, $medidas);
        // Medido no contêiner: uns 300 KB e 1 s.
        self::assertLessThan(4 * 1024 * 1024, $pico, sprintf('Pico de %.1f MB.', $pico / 1048576));
        self::assertLessThan(10.0, $tempo);
        self::assertSame([[3000 => [595.0, 842.0]], null], self::ler($pdf, 3000, 3000), 'A linha falsa da fonte não chega à medida.');
    }

    #[TestDox('Arquivo que não é PDF não dá medida e diz o código de saída do Ghostscript')]
    public function testNaoEPdf(): void
    {
        self::assertSame([[], 'codigo 1'], self::ler("isto não é PDF\n", 1, self::TETO));
    }

    #[TestDox('Binário que não existe não dá medida e diz o código 127, sem exceção')]
    public function testBinarioInexistente(): void
    {
        self::assertSame([[], 'codigo 127'], MedidasDoPdfEnviado::ler('/nao/existe/gs', '/nao/existe.pdf', 1, self::TETO));
    }

    #[TestDox('Ghostscript parado no tempo ou no teto volta com o motivo e não sobra vivo')]
    public function testSemOrfao(): void
    {
        $dir = sys_get_temp_dir() . '/medidas-orfao-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);

        try {
            $inicio = microtime(true);
            self::assertSame([[], 'tempo'], MedidasDoPdfEnviado::ler(self::falso($dir, 'exec sleep 30'), '/nao/existe.pdf', 1, self::TETO, 0.5));
            self::assertLessThan(5.0, microtime(true) - $inicio);
            self::assertProcessoTerminou($dir);

            self::assertSame([[], 'teto'], MedidasDoPdfEnviado::ler(self::falso($dir, 'exec yes 0123456789'), '/nao/existe.pdf', 1, self::TETO));
            self::assertProcessoTerminou($dir);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    private static function linhas(string ...$linhas): string
    {
        return implode('', array_map(static fn (string $linha): string => self::MARCA . ' ' . $linha . "\n", $linhas));
    }

    /**
     * @return array{array<int, array{float, float}>, ?string}
     */
    private static function ler(string $pdf, int $primeira, int $ultima): array
    {
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript ausente.');
        }

        $area = AreaTemporariaPrivada::criar('testemedidas');
        try {
            return MedidasDoPdfEnviado::ler(GhostscriptDeTeste::binario(), $area->gravar($pdf, 'pdf'), $primeira, $ultima);
        } finally {
            $area->liberar();
        }
    }

    /** Um "Ghostscript" que grava o próprio PID e troca de corpo com `exec`: o PID gravado é o do processo até o fim. */
    private static function falso(string $dir, string $corpo): string
    {
        $caminho = $dir . '/gs-falso';
        file_put_contents($caminho, sprintf("#!/bin/sh\necho \$\$ > %s\n%s\n", escapeshellarg($dir . '/pid'), $corpo));
        chmod($caminho, 0o700);

        return $caminho;
    }

    private static function assertProcessoTerminou(string $dir): void
    {
        $pid = (int) trim((string) file_get_contents($dir . '/pid'));

        self::assertGreaterThan(0, $pid);
        self::assertDirectoryDoesNotExist('/proc/' . $pid, 'O Ghostscript sobrou (vivo ou zumbi).');
    }
}
