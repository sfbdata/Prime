<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Service\ExecucaoDoGhostscript;
use App\Shared\Service\OrcamentoDeMemoriaDeImagem;

/**
 * As páginas do PDF enviado em imagem (E3-1): Ghostscript `png16m`, uma página por vez, dentro de uma
 * {@see AreaTemporariaPrivada} (o PDF materializado e a imagem de saída somem com ela).
 *
 * A 225 dpi uma A4 sai do tamanho da caixa onde a página entra (790 x 1050 unidades = 1959 x 2604 px). Página muito
 * maior que a caixa (cartaz, planta) é rasterizada de novo com a resolução reduzida, para a GD não estourar a memória.
 */
final class RasterDoPdfEnviado
{
    private const DPI = 225;
    private const CAIXA_PX = [1959, 2604];
    private const FOLGA_DA_CAIXA = 1.25;
    private const TEMPO_POR_PAGINA = 60.0;
    private const FINALIDADE = 'assinaturapdf';

    private ?AreaTemporariaPrivada $area = null;
    private ?string $pdf = null;

    public function __construct(
        private readonly string $ghostscriptBin,
        private readonly ?string $bytes,
    ) {
    }

    /**
     * A página $numero (a partir de 1). Quem recebe libera com `imagedestroy`.
     *
     * @throws FalhaAoMontarPdf
     */
    public function pagina(int $numero): \GdImage
    {
        if ($this->bytes === null) {
            throw new FalhaAoMontarPdf('A folha tem página do PDF enviado, mas o PDF não veio.');
        }

        $this->area ??= AreaTemporariaPrivada::criar(self::FINALIDADE);
        $this->pdf ??= $this->area->gravar($this->bytes, 'pdf');
        // Uma área por página: a imagem de uma página não sobra no disco nem é lida no lugar da seguinte.
        $daPagina = AreaTemporariaPrivada::criar(self::FINALIDADE);

        try {
            return $this->decodificar($numero, $daPagina->caminho() . '/pagina.png');
        } finally {
            $daPagina->liberar();
        }
    }

    public function liberar(): void
    {
        $this->area?->liberar();
        $this->area = null;
        $this->pdf = null;
    }

    /** @throws FalhaAoMontarPdf */
    private function decodificar(int $numero, string $saida): \GdImage
    {
        [$largura, $altura] = $this->rasterizar($numero, $saida, self::DPI);
        $reducao = min(1.0, self::CAIXA_PX[0] * self::FOLGA_DA_CAIXA / $largura, self::CAIXA_PX[1] * self::FOLGA_DA_CAIXA / $altura);
        if ($reducao < 1.0) {
            [$largura, $altura] = $this->rasterizar($numero, $saida, max(10, (int) floor(self::DPI * $reducao)));
        }

        if (!OrcamentoDeMemoriaDeImagem::cabe($largura, $altura)) {
            throw new FalhaAoMontarPdf(sprintf('Sem memória para a página %d do PDF enviado.', $numero));
        }

        $imagem = @imagecreatefrompng($saida);
        if ($imagem === false) {
            throw new FalhaAoMontarPdf(sprintf('A página %d do PDF enviado não virou imagem.', $numero));
        }

        return $imagem;
    }

    /**
     * @return array{int, int} largura e altura da imagem gerada
     *
     * @throws FalhaAoMontarPdf
     */
    private function rasterizar(int $numero, string $saida, int $dpi): array
    {
        try {
            $processo = ExecucaoDoGhostscript::rodar([
                $this->ghostscriptBin,
                '-q',
                '-dSAFER',
                '-dBATCH',
                '-dNOPAUSE',
                '-sDEVICE=png16m',
                '-r' . $dpi,
                '-dTextAlphaBits=4',
                '-dGraphicsAlphaBits=4',
                '-dUseCropBox',
                '-dFirstPage=' . $numero,
                '-dLastPage=' . $numero,
                '-sOutputFile=' . $saida,
                (string) $this->pdf,
            ], self::TEMPO_POR_PAGINA);
        } catch (\RuntimeException $e) {
            throw new FalhaAoMontarPdf(sprintf('O Ghostscript não rasterizou a página %d: %s', $numero, $e->getMessage()), 0, $e);
        }

        $medidas = $processo->isSuccessful() ? @getimagesize($saida) : false;
        if ($medidas === false || $medidas[0] < 1 || $medidas[1] < 1) {
            throw new FalhaAoMontarPdf(sprintf('O Ghostscript não rasterizou a página %d do PDF enviado.', $numero));
        }

        return [$medidas[0], $medidas[1]];
    }
}
