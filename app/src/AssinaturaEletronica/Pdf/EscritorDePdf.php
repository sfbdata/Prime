<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;

/**
 * O PDF do desenho (dc:421-425, E3-1), sem biblioteca: cada página é uma JPEG desenhada inteira numa folha A4 de
 * 595,28 x 841,89 pt. Objetos: 1 catálogo, 2 árvore de páginas, a partir de 3 um trio por página (página, imagem
 * `DCTDecode`, conteúdo) e por último as informações (`/Title`, `/Producer`).
 *
 * O {@see PerfilDoPdf} decide a qualidade da JPEG e se a imagem é reduzida antes: a folha e a matriz que desenha a
 * imagem são as mesmas nos dois perfis, só muda quantos pixels a imagem tem (o `/Width` e o `/Height` dela).
 *
 * Escreve em pedaços, na ordem: {@see inicio()}, uma {@see pagina()} por vez e {@see fim()}. A árvore de páginas vai
 * no fim (a tabela de referências aceita qualquer ordem), para ninguém precisar saber antes quantas páginas haverá.
 * A JPEG é gerada em memória (destino nulo); quem grava o arquivo é o armazenamento.
 */
final class EscritorDePdf
{
    /** A qualidade da JPEG no perfil do desenho, o padrão ({@see PerfilDoPdf::qualidade()}). */
    public const QUALIDADE = 90;
    private const LARGURA_PT = '595.28';
    private const ALTURA_PT = '841.89';
    private const PRIMEIRO_OBJETO_DE_PAGINA = 3;

    private int $tamanho = 0;
    /** @var array<int, int> */
    private array $posicoes = [];
    private int $paginas = 0;

    public function __construct(private readonly PerfilDoPdf $perfil = PerfilDoPdf::Desenho)
    {
    }

    public function inicio(): string
    {
        return $this->bruto("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n") . $this->objeto(1, '<< /Type /Catalog /Pages 2 0 R >>');
    }

    /** @throws FalhaAoMontarPdf */
    public function pagina(\GdImage $imagem): string
    {
        [$jpeg, $largura, $altura] = $this->jpeg($imagem);
        $pagina = self::PRIMEIRO_OBJETO_DE_PAGINA + $this->paginas * 3;
        ++$this->paginas;

        $saida = $this->objeto($pagina, sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /XObject << /Im0 %d 0 R >> >> /Contents %d 0 R >>',
            self::LARGURA_PT,
            self::ALTURA_PT,
            $pagina + 1,
            $pagina + 2,
        ));

        $this->posicoes[$pagina + 1] = $this->tamanho;
        $saida .= $this->bruto(sprintf(
            "%d 0 obj\n<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n",
            $pagina + 1,
            $largura,
            $altura,
            \strlen($jpeg),
        ) . $jpeg . "\nendstream\nendobj\n");

        $desenho = sprintf('q %s 0 0 %s 0 0 cm /Im0 Do Q', self::LARGURA_PT, self::ALTURA_PT);

        return $saida . $this->objeto($pagina + 2, sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($desenho), $desenho));
    }

    /**
     * O título e o produtor vão nas informações do PDF (`/Title` e `/Producer`).
     *
     * @throws FalhaAoMontarPdf sem nenhuma página
     */
    public function fim(string $titulo, string $produtor): string
    {
        if ($this->paginas === 0) {
            throw new FalhaAoMontarPdf('O PDF precisa de pelo menos uma página.');
        }

        $filhas = [];
        for ($i = 0; $i < $this->paginas; ++$i) {
            $filhas[] = (self::PRIMEIRO_OBJETO_DE_PAGINA + $i * 3) . ' 0 R';
        }
        $saida = $this->objeto(2, sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $filhas), $this->paginas));

        $informacoes = self::PRIMEIRO_OBJETO_DE_PAGINA + $this->paginas * 3;
        $saida .= $this->objeto($informacoes, sprintf('<< /Title (%s) /Producer (%s) >>', self::textoDoPdf($titulo), self::textoDoPdf($produtor)));

        $referencias = $this->tamanho;
        $tabela = sprintf("xref\n0 %d\n0000000000 65535 f \n", $informacoes + 1);
        for ($i = 1; $i <= $informacoes; ++$i) {
            $tabela .= sprintf("%010d 00000 n \n", $this->posicoes[$i]);
        }
        $tabela .= sprintf("trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", $informacoes + 1, $informacoes, $referencias);

        return $saida . $this->bruto($tabela);
    }

    private function objeto(int $numero, string $corpo): string
    {
        $this->posicoes[$numero] = $this->tamanho;

        return $this->bruto(sprintf("%d 0 obj\n%s\nendobj\n", $numero, $corpo));
    }

    private function bruto(string $bytes): string
    {
        $this->tamanho += \strlen($bytes);

        return $bytes;
    }

    /**
     * A JPEG da página no perfil, com a largura e a altura dela em pixels (as do `/Width` e do `/Height` da imagem).
     * No perfil leve a página é reduzida antes, e a reduzida é liberada assim que vira JPEG.
     *
     * @return array{string, int, int}
     *
     * @throws FalhaAoMontarPdf
     */
    private function jpeg(\GdImage $imagem): array
    {
        $reducao = $this->perfil->reducao();
        $reduzida = null;
        if ($reducao !== 1.0) {
            $reduzida = imagescale($imagem, (int) round(imagesx($imagem) * $reducao), (int) round(imagesy($imagem) * $reducao), \IMG_BICUBIC);
            if ($reduzida === false) {
                throw new FalhaAoMontarPdf('A GD não reduziu a página.');
            }
        }
        $saida = $reduzida ?? $imagem;
        $largura = imagesx($saida);
        $altura = imagesy($saida);

        try {
            ob_start();
            $gerou = imagejpeg($saida, null, $this->perfil->qualidade());
            $jpeg = (string) ob_get_clean();
        } finally {
            if ($reduzida !== null) {
                imagedestroy($reduzida);
            }
        }

        if (!$gerou || $jpeg === '') {
            throw new FalhaAoMontarPdf('A GD não gerou a JPEG da página.');
        }

        return [$jpeg, $largura, $altura];
    }

    /** Texto literal do PDF: barra invertida e parênteses escapados. */
    private static function textoDoPdf(string $texto): string
    {
        return strtr($texto, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
