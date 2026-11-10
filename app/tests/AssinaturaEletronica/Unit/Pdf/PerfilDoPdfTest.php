<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use App\AssinaturaEletronica\Pdf\EscritorDePdf;
use App\AssinaturaEletronica\Pdf\MontadorDoPdf;
use App\AssinaturaEletronica\Pdf\PerfilDoPdf;
use App\AssinaturaEletronica\Pdf\RasterDoPdfEnviado;
use App\AssinaturaEletronica\Pdf\Tela;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\SituacaoDaConferencia;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * O mesmo documento montado nos dois perfis: o leve tem a imagem com metade dos pixels e pesa menos da metade, na
 * mesma folha. Sem perfil, o montador continua no do desenho.
 */
#[CoversClass(PerfilDoPdf::class)]
#[CoversClass(MontadorDoPdf::class)]
#[CoversClass(EscritorDePdf::class)]
final class PerfilDoPdfTest extends TestCase
{
    /** A metade de 2480 x 3507 px, arredondada: 1753,5 vira 1754. */
    private const METADE = [1240, 1754];

    /**
     * Lado do bloco, em pixels da página rasterizada, em que as duas imagens são comparadas pela média: o bloco apaga
     * o ruído fino da JPEG 70 e da redução, mas não um deslocamento. Todos os pixels entram na média: amostrar só
     * os pares cria diferença artificial nas linhas finas. O pior bloco isolado do QR varia entre Ghostscript 10.02
     * e 10.05; o alinhamento é provado comparando também deslocamentos de 9 px (cerca de 1 mm) nas quatro direções.
     */
    private const BLOCO = 31;

    /** @var array<string, PdfLido> o PDF de cada perfil, montado uma vez para a classe toda */
    private static array $montados = [];

    #[TestDox('Desenho: JPEG 90 sem redução; leve: JPEG 70 com a página reduzida à metade')]
    public function testValores(): void
    {
        self::assertSame(90, PerfilDoPdf::Desenho->qualidade());
        self::assertSame(EscritorDePdf::QUALIDADE, PerfilDoPdf::Desenho->qualidade());
        self::assertSame(1.0, PerfilDoPdf::Desenho->reducao());
        self::assertSame(70, PerfilDoPdf::Leve->qualidade());
        self::assertSame(0.5, PerfilDoPdf::Leve->reducao());
        self::assertSame(PerfilDoPdf::Leve, PerfilDoPdf::from('leve'));
        self::assertSame(PerfilDoPdf::Desenho, PerfilDoPdf::from('desenho'));
    }

    #[TestDox('Leve: menos da metade dos bytes, imagem de 1240 x 1754 px em JPEG 70 e a mesma folha do desenho')]
    public function testLeveContraODesenho(): void
    {
        FolhaDeTeste::exigirFontes($this);
        $desenho = self::montado(PerfilDoPdf::Desenho);
        $leve = self::montado(PerfilDoPdf::Leve);

        self::assertSame($desenho->paginas(), $leve->paginas());
        self::assertLessThan(\strlen($desenho->bytes) / 2, \strlen($leve->bytes));

        self::assertSame([[Tela::LARGURA_PX, Tela::ALTURA_PX]], $desenho->medidasDasImagens());
        self::assertSame([self::METADE], $leve->medidasDasImagens());
        self::assertSame(self::METADE, \array_slice(getimagesizefromstring($leve->jpegs()[0]) ?: [], 0, 2));
        self::assertSame(PdfLido::quantizacaoDaQualidade(70), PdfLido::quantizacao($leve->jpegs()[0]));

        self::assertSame(self::folhas($desenho), self::folhas($leve));
        self::assertSame(['[0 0 595.28 841.89]'], self::folhas($leve));
        self::assertSame(self::matrizes($desenho), self::matrizes($leve));
    }

    #[TestDox('O PDF leve abre no Ghostscript: A4, e a página rasterizada é a mesma do desenho')]
    public function testLeveNoGhostscript(): void
    {
        FolhaDeTeste::exigirFontes($this);
        if (!GhostscriptDeTeste::disponivel()) {
            self::markTestSkipped('Ghostscript ausente.');
        }

        $leve = self::rasterizada(self::montado(PerfilDoPdf::Leve));
        $desenho = self::rasterizada(self::montado(PerfilDoPdf::Desenho));

        try {
            // A4 a 225 dpi, como em RasterDoPdfEnviadoTest: o Ghostscript leu a mesma folha nos dois.
            self::assertSame([1860, 2631], [imagesx($leve), imagesy($leve)]);
            self::assertSame([imagesx($desenho), imagesy($desenho)], [imagesx($leve), imagesy($leve)]);
            $media = self::diferencaMediaPorBloco($leve, $desenho);
            self::assertLessThan(3.0, $media, 'A imagem reduzida cobre a folha inteira, no mesmo lugar da do desenho.');
            self::assertMelhorAlinhamento($leve, $desenho, $media);
        } finally {
            imagedestroy($leve);
            imagedestroy($desenho);
        }
    }

    /** A comparação tem de distinguir a página alinhada das mesmas páginas deslocadas em cerca de 1 mm. */
    private static function assertMelhorAlinhamento(\GdImage $leve, \GdImage $desenho, float $media): void
    {
        foreach ([[9, 0], [-9, 0], [0, 9], [0, -9]] as [$dx, $dy]) {
            $deslocada = imagecreatetruecolor(imagesx($desenho), imagesy($desenho));
            try {
                imagefill($deslocada, 0, 0, 0xFFFFFF);
                imagecopy($deslocada, $desenho, $dx, $dy, 0, 0, imagesx($desenho), imagesy($desenho));
                self::assertLessThan(
                    self::diferencaMediaPorBloco($leve, $deslocada),
                    $media,
                    sprintf('O perfil leve deve corresponder melhor à página alinhada do que à deslocada (%d, %d).', $dx, $dy),
                );
            } finally {
                imagedestroy($deslocada);
            }
        }
    }

    #[TestDox('O motor de conferência lê o PDF leve: PDF sem assinatura, não ilegível')]
    public function testLeveNoMotor(): void
    {
        FolhaDeTeste::exigirFontes($this);

        $resultado = (new ConferenciaDePdf())->conferir(self::montado(PerfilDoPdf::Leve)->bytes);

        self::assertSame(SituacaoDaConferencia::SemAssinatura, $resultado->situacao);
        self::assertSame(ConferenciaDePdf::MOTIVO_SEM_ASSINATURA, $resultado->motivo);
    }

    #[TestDox('Sem perfil, o montador usa o do desenho: imagem de 2480 x 3507 px em JPEG 90, os mesmos bytes')]
    public function testPadraoEhODesenho(): void
    {
        FolhaDeTeste::exigirFontes($this);

        $padrao = PdfLido::dosPedacos(self::montador()->emPedacos(FolhaDeTeste::folha()));

        self::assertSame([[2480, 3507]], $padrao->medidasDasImagens());
        self::assertSame([2480, 3507], \array_slice(getimagesizefromstring($padrao->jpegs()[0]) ?: [], 0, 2));
        self::assertSame(PdfLido::quantizacaoDaQualidade(90), PdfLido::quantizacao($padrao->jpegs()[0]));
        self::assertSame(self::montado(PerfilDoPdf::Desenho)->bytes, $padrao->bytes);
    }

    private static function montador(): MontadorDoPdf
    {
        return new MontadorDoPdf(GhostscriptDeTeste::binario());
    }

    /** A folha de uma página do conteúdo (sem manifesto), montada no perfil uma vez só. */
    private static function montado(PerfilDoPdf $perfil): PdfLido
    {
        return self::$montados[$perfil->value] ??= PdfLido::dosPedacos(self::montador()->emPedacos(FolhaDeTeste::folha(), $perfil));
    }

    /** @return list<string> o `/MediaBox` de cada página */
    private static function folhas(PdfLido $pdf): array
    {
        preg_match_all('#/MediaBox (\[[^\]]*\])#', $pdf->bytes, $m);

        return $m[1];
    }

    /** @return list<string> o conteúdo de cada página: a matriz que desenha a imagem */
    private static function matrizes(PdfLido $pdf): array
    {
        preg_match_all('#stream\n(q [^\n]* cm /Im0 Do Q)\nendstream#', $pdf->bytes, $m);

        return $m[1];
    }

    /** A primeira página do PDF pelo Ghostscript, como o PDF enviado é lido na montagem. */
    private static function rasterizada(PdfLido $pdf): \GdImage
    {
        $raster = new RasterDoPdfEnviado(GhostscriptDeTeste::binario(), $pdf->bytes, new NullLogger(), hash('sha256', $pdf->bytes));
        try {
            return $raster->pagina(1);
        } finally {
            $raster->liberar();
        }
    }

    /**
     * A diferença entre as médias de cor de cada bloco das duas imagens (do mesmo tamanho), por canal de 0 a 255: a
     * média de todos os blocos.
     */
    private static function diferencaMediaPorBloco(\GdImage $a, \GdImage $b): float
    {
        $soma = 0.0;
        $canais = 0;
        for ($y = 0; $y + self::BLOCO <= imagesy($a); $y += self::BLOCO) {
            for ($x = 0; $x + self::BLOCO <= imagesx($a); $x += self::BLOCO) {
                foreach (array_map(null, self::mediaDoBloco($a, $x, $y), self::mediaDoBloco($b, $x, $y)) as [$canalA, $canalB]) {
                    $diferenca = abs($canalA - $canalB);
                    $soma += $diferenca;
                    ++$canais;
                }
            }
        }

        return $soma / $canais;
    }

    /** @return array{float, float, float} a média de cada canal de todos os pixels do bloco que começa em ($x, $y) */
    private static function mediaDoBloco(\GdImage $imagem, int $x, int $y): array
    {
        $soma = [0, 0, 0];
        $n = 0;
        for ($py = $y; $py < $y + self::BLOCO; ++$py) {
            for ($px = $x; $px < $x + self::BLOCO; ++$px) {
                $cor = imagecolorat($imagem, $px, $py);
                $soma[0] += ($cor >> 16) & 0xFF;
                $soma[1] += ($cor >> 8) & 0xFF;
                $soma[2] += $cor & 0xFF;
                ++$n;
            }
        }

        return [$soma[0] / $n, $soma[1] / $n, $soma[2] / $n];
    }
}
