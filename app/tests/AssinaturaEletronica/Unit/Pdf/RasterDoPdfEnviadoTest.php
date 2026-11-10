<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\AssinaturaEletronica\Pdf\MedidasDoPdfEnviado;
use App\AssinaturaEletronica\Pdf\RasterDoPdfEnviado;
use App\AssinaturaEletronica\Service\ValidadorDeBlocos;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * As páginas do PDF enviado em imagem: a A4 sai a 225 dpi, como sempre; a página maior que a caixa (1959 x 2604 px,
 * com a folga de 1,25: até 2449 x 3255) já sai do Ghostscript reduzida, numa passada só. A imagem nunca passa do
 * `-g` calculado; a página sem medida sai inteira, encaixada na caixa com a folga.
 */
#[CoversClass(RasterDoPdfEnviado::class)]
#[CoversClass(MedidasDoPdfEnviado::class)]
final class RasterDoPdfEnviadoTest extends TestCase
{
    /** Antes, a página de 200 x 200 pol ia inteira a 225 dpi (45000 x 45000 px) e só parava no tempo de 60 s. */
    private const RAPIDO_SEGUNDOS = 20.0;
    private const DOCUMENTO = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const LEITURA_INCOMPLETA = 'O tamanho das páginas do PDF enviado não foi lido até o fim.';
    private const ENCAIXADA = 'A página do PDF enviado ficou sem medida e vai inteira, encaixada.';

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
        $raster = new RasterDoPdfEnviado(GhostscriptDeTeste::binario(), $pdf, new NullLogger(), self::DOCUMENTO);

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

    #[TestDox('Página depois do teto de páginas é medida sozinha: o PDF hostil sai inteiro, sem aviso')]
    public function testPaginaDepoisDoTetoDePaginas(): void
    {
        $avisos = new AvisosDeTeste();
        $raster = new RasterDoPdfEnviado(GhostscriptDeTeste::binario(), PdfDeCaixas::comSaidaGigante(), $avisos, self::DOCUMENTO);

        try {
            // 595 x 842 pt a 225 dpi = 1859,4 x 2631,3 px.
            self::assertSame([1859, 2631], self::tamanho($raster->pagina(1)));
            self::assertSame([1859, 2631], self::tamanho($raster->pagina(2999)));
            self::assertSame([1859, 2631], self::tamanho($raster->pagina(3000)), 'A linha falsa da fonte (100 x 100 pt) não corta mais a página.');
        } finally {
            $raster->liberar();
        }
        self::assertSame([], $avisos->registros);
    }

    #[TestDox('Página que nem a segunda leitura mede sai inteira, encaixada na caixa com a folga, e o log diz documento, página e motivo')]
    public function testPaginaSemMedidaSaiInteira(): void
    {
        $avisos = new AvisosDeTeste();
        $imagem = self::semMedida(PdfDeCaixas::comCantos(1200, 1600), $avisos);

        // 1200 x 1600 pt a 225 dpi seriam 3750 x 5000 px: cortada no -g, a página perderia o canto azul.
        self::assertSame([2449, 3255], [imagesx($imagem), imagesy($imagem)]);
        self::assertSame(0xFF0000, imagecolorat($imagem, 30, 3225), 'Canto de baixo à esquerda.');
        self::assertSame(0x0000FF, imagecolorat($imagem, 2419, 30), 'Canto de cima à direita: a página veio inteira.');
        imagedestroy($imagem);

        $contexto = ['documento' => self::DOCUMENTO, 'pagina' => 1, 'motivo' => 'codigo 1'];
        self::assertSame([
            ['warning', 'O tamanho das páginas do PDF enviado não foi lido até o fim.', $contexto],
            ['warning', 'A página do PDF enviado ficou sem medida e vai inteira, encaixada.', $contexto],
        ], $avisos->registros);
    }

    #[TestDox('Sem medida, a página deitada pelo Rotate fica de lado: o -dPDFFitPage ignora o Rotate')]
    public function testPaginaGiradaSemMedidaFicaDeLado(): void
    {
        $imagem = self::semMedida(PdfDeCaixas::comCantos(1200, 1600, '/Rotate 90'), new AvisosDeTeste());

        self::assertSame([2449, 3255], [imagesx($imagem), imagesy($imagem)]);
        self::assertSame(0xFF0000, imagecolorat($imagem, 30, 3225), 'Igual à página sem Rotate.');
        self::assertSame(0x0000FF, imagecolorat($imagem, 2419, 30), 'Igual à página sem Rotate.');
        imagedestroy($imagem);
    }

    #[TestDox('Sem o Ghostscript, a falta da medida vai para o log e a página falha com a exceção da montagem')]
    public function testBinarioInexistente(): void
    {
        $avisos = new AvisosDeTeste();
        $raster = new RasterDoPdfEnviado('/nao/existe/gs', PdfDeCaixas::gerar('/MediaBox [0 0 595 842]'), $avisos, self::DOCUMENTO);

        try {
            $raster->pagina(1);
            self::fail('Sem o Ghostscript a página não sai.');
        } catch (FalhaAoMontarPdf $e) {
            self::assertStringContainsString('não rasterizou a página 1', $e->getMessage());
        } finally {
            $raster->liberar();
        }

        self::assertSame(['codigo 127', 'codigo 127'], array_map(static fn (array $registro): mixed => $registro[2]['motivo'], $avisos->registros));
    }

    #[TestDox('PDF hostil com memory_limit de 128 MB: as páginas 1, 2000 e 3000 saem, com pouca memória')]
    public function testPdfHostilEm128Mb(): void
    {
        $area = AreaTemporariaPrivada::criar('testeraster');

        try {
            $script = $area->gravar(<<<'PHP'
                <?php
                require $argv[1];
                $raster = new App\AssinaturaEletronica\Pdf\RasterDoPdfEnviado($argv[2], App\Tests\AssinaturaEletronica\Unit\Pdf\PdfDeCaixas::comSaidaGigante(), new Psr\Log\NullLogger(), str_repeat('a', 64));
                $inicio = microtime(true);
                foreach ([1, 2000, 3000] as $numero) {
                    $imagem = $raster->pagina($numero);
                    echo imagesx($imagem), 'x', imagesy($imagem), ' ';
                    imagedestroy($imagem);
                }
                $raster->liberar();
                printf('%.1f %.1f', memory_get_peak_usage() / 1048576, microtime(true) - $inicio);
                PHP, 'php');
            $processo = new Process([\PHP_BINARY, '-d', 'memory_limit=128M', $script, \dirname(__DIR__, 4) . '/vendor/autoload.php', GhostscriptDeTeste::binario()]);
            $processo->setTimeout(120);
            $processo->run();
        } finally {
            $area->liberar();
        }

        self::assertSame(0, $processo->getExitCode(), $processo->getErrorOutput() . $processo->getOutput());
        self::assertStringStartsWith('1859x2631 1859x2631 1859x2631 ', $processo->getOutput());
        [$pico, $segundos] = array_map(floatval(...), \array_slice(explode(' ', $processo->getOutput()), 3));
        // Medido no contêiner: 59 MB e 4,6 s (a leitura das 2000 medidas leva 1,1 s). Passar de 128 MB seria Fatal.
        self::assertLessThan(96.0, $pico, sprintf('Pico de %.1f MB em %.1f s.', $pico, $segundos));
    }

    #[TestDox('Leitura de todas parada no tempo: nenhuma página é medida de novo, todas vão inteiras, com log')]
    public function testTempoDaLeituraDeTodasSemNovaMedida(): void
    {
        $area = AreaTemporariaPrivada::criar('testeraster');
        $avisos = new AvisosDeTeste();
        $pdf = PdfDeCaixas::gerar('/MediaBox [0 0 595 842]', '/MediaBox [0 0 595 842]');
        $raster = new RasterDoPdfEnviado(self::gsQueMede($area, 'exec sleep 30', 'exec sleep 30'), $pdf, $avisos, self::DOCUMENTO, 0.5);

        $inicio = microtime(true);
        try {
            self::assertSame([2449, 3255], self::tamanho($raster->pagina(1)));
            self::assertSame([2449, 3255], self::tamanho($raster->pagina(2)));
            $chamadas = self::chamadas($area);
        } finally {
            $raster->liberar();
            $area->liberar();
        }

        self::assertSame(['todas'], $chamadas, 'Só a leitura de todas, nenhuma de uma página.');
        self::assertLessThan(self::RAPIDO_SEGUNDOS, microtime(true) - $inicio);
        self::assertSame([
            [self::LEITURA_INCOMPLETA, 1, 'tempo'],
            [self::ENCAIXADA, 1, 'tempo'],
            [self::ENCAIXADA, 2, 'tempo'],
        ], self::motivos($avisos));
    }

    #[TestDox('As novas medidas página a página somam no máximo o orçamento do documento; depois dele, FitPage com log')]
    public function testOrcamentoDasNovasMedidas(): void
    {
        $area = AreaTemporariaPrivada::criar('testeraster');
        $avisos = new AvisosDeTeste();
        $pdf = PdfDeCaixas::gerar('/MediaBox [0 0 595 842]', '/MediaBox [0 0 595 842]', '/MediaBox [0 0 595 842]');
        // Orçamento de 2 s; cada nova medida leva 1,2 s e não mede: a 1ª cabe, a 2ª para no que sobrou, a 3ª nem roda.
        $raster = new RasterDoPdfEnviado(self::gsQueMede($area, 'exit 1', 'exec sleep 1.2'), $pdf, $avisos, self::DOCUMENTO, 2.0);

        try {
            foreach ([1, 2, 3] as $numero) {
                self::assertSame([2449, 3255], self::tamanho($raster->pagina($numero)));
            }
            $chamadas = self::chamadas($area);
        } finally {
            $raster->liberar();
            $area->liberar();
        }

        self::assertSame(['todas', 'uma', 'uma'], $chamadas);
        self::assertSame([
            [self::LEITURA_INCOMPLETA, 1, 'codigo 1'],
            [self::ENCAIXADA, 1, 'sem medida'],
            [self::ENCAIXADA, 2, 'tempo'],
            [self::ENCAIXADA, 3, 'orcamento'],
        ], self::motivos($avisos));
    }

    /**
     * Um Ghostscript que, ao medir, anota a chamada (`todas` ou `uma`) e roda o trecho pedido; ao desenhar, chama o
     * binário de verdade.
     */
    private static function gsQueMede(AreaTemporariaPrivada $area, string $aoMedirTodas, string $aoMedirUma): string
    {
        $script = <<<'SH'
            #!/bin/sh
            medir=0; todas=0
            for a in "$@"; do
              case "$a" in
                -dNODISPLAY) medir=1 ;;
                -dBJULTIMA=%d) todas=1 ;;
              esac
            done
            if [ "$medir" = 1 ] && [ "$todas" = 1 ]; then echo todas >> %s; %s; fi
            if [ "$medir" = 1 ]; then echo uma >> %s; %s; fi
            exec %s "$@"

            SH;
        $chamadas = escapeshellarg($area->caminho() . '/chamadas');
        $gs = $area->gravar(sprintf($script, ValidadorDeBlocos::PAGINAS_SEM_CONTAGEM, $chamadas, $aoMedirTodas, $chamadas, $aoMedirUma, escapeshellarg(GhostscriptDeTeste::binario())), 'sh');
        chmod($gs, 0o700);

        return $gs;
    }

    /** @return list<string> */
    private static function chamadas(AreaTemporariaPrivada $area): array
    {
        return file($area->caminho() . '/chamadas', \FILE_IGNORE_NEW_LINES) ?: [];
    }

    /** @return list<array{string, mixed, mixed}> mensagem, página e motivo de cada aviso */
    private static function motivos(AvisosDeTeste $avisos): array
    {
        return array_map(static fn (array $registro): array => [$registro[1], $registro[2]['pagina'] ?? null, $registro[2]['motivo'] ?? null], $avisos->registros);
    }

    /** A página 1 com um Ghostscript que falha na leitura das medidas e desenha com o binário de verdade. */
    private static function semMedida(string $pdf, AvisosDeTeste $avisos): \GdImage
    {
        $area = AreaTemporariaPrivada::criar('testeraster');
        $gs = $area->gravar(sprintf("#!/bin/sh\nfor a in \"\$@\"; do [ \"\$a\" = -dNODISPLAY ] && exit 1; done\nexec %s \"\$@\"\n", escapeshellarg(GhostscriptDeTeste::binario())), 'sh');
        chmod($gs, 0o700);
        $raster = new RasterDoPdfEnviado($gs, $pdf, $avisos, self::DOCUMENTO);

        try {
            return $raster->pagina(1);
        } finally {
            $raster->liberar();
            $area->liberar();
        }
    }

    /** @return array{int, int} */
    private static function medidas(string $pdf, int $pagina): array
    {
        $raster = new RasterDoPdfEnviado(GhostscriptDeTeste::binario(), $pdf, new NullLogger(), self::DOCUMENTO);

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
