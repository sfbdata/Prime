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
 * maior que a caixa (cartaz, planta) já sai do Ghostscript com a resolução reduzida: o tamanho de cada página é lido
 * antes ({@see MedidasDoPdfEnviado}) e a página é rasterizada uma vez só, sem passar da caixa com a folga. Sem isso,
 * uma página de 200 x 200 pol seria rasterizada inteira a 225 dpi e estouraria o tempo antes de qualquer redução.
 */
final class RasterDoPdfEnviado
{
    private const DPI = 225;
    /** Abaixo de 2 dpi o Ghostscript recusa a página (rangecheck em setscreen). */
    private const MENOR_DPI = 2;
    private const CAIXA_PX = [1959, 2604];
    private const FOLGA_DA_CAIXA = 1.25;
    private const PONTOS_POR_POLEGADA = 72;
    private const TEMPO_POR_PAGINA = 60.0;
    private const FINALIDADE = 'assinaturapdf';

    private ?AreaTemporariaPrivada $area = null;
    private ?string $pdf = null;
    /** @var array<int, array{float, float}>|null largura e altura de cada página, em pontos */
    private ?array $medidas = null;

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
        $this->medidas = null;
    }

    /** @throws FalhaAoMontarPdf */
    private function decodificar(int $numero, string $saida): \GdImage
    {
        [$largura, $altura] = $this->rasterizar($numero, $saida, $this->dpi($numero));

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
     * 225 dpi, ou menos quando a página passaria da caixa com a folga: a mesma conta de antes, feita com o tamanho
     * lido da página em vez da imagem já rasterizada. Página que o Ghostscript não descreveu vai a 225 dpi, e a
     * imagem grande demais ainda para no orçamento de memória.
     *
     * @throws FalhaAoMontarPdf página tão grande que nem a 2 dpi caberia na caixa
     */
    private function dpi(int $numero): int
    {
        $this->medidas ??= MedidasDoPdfEnviado::ler($this->ghostscriptBin, (string) $this->pdf);
        if (!isset($this->medidas[$numero])) {
            return self::DPI;
        }

        [$largura, $altura] = $this->medidas[$numero];
        $porPonto = self::DPI / self::PONTOS_POR_POLEGADA;
        $reducao = min(1.0, self::CAIXA_PX[0] * self::FOLGA_DA_CAIXA / ($largura * $porPonto), self::CAIXA_PX[1] * self::FOLGA_DA_CAIXA / ($altura * $porPonto));
        $dpi = (int) floor(self::DPI * $reducao);
        if ($dpi < self::MENOR_DPI) {
            throw new FalhaAoMontarPdf(sprintf('A página %d do PDF enviado é grande demais para virar imagem.', $numero));
        }

        return $dpi;
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
