<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

/**
 * Lê o PDF que o {@see \App\AssinaturaEletronica\Pdf\EscritorDePdf} escreve: quantas páginas, o título e a JPEG de
 * cada página (para amostrar pixels sem rasterizar de novo).
 */
final class PdfLido
{
    public function __construct(public readonly string $bytes)
    {
    }

    /**
     * Junta os pedaços do {@see \App\AssinaturaEletronica\Pdf\MontadorDoPdf::emPedacos()}: só o teste junta o PDF
     * inteiro numa string; quem grava ou entrega consome pedaço a pedaço.
     *
     * @param iterable<string> $pedacos
     */
    public static function dosPedacos(iterable $pedacos): self
    {
        $bytes = '';
        foreach ($pedacos as $pedaco) {
            $bytes .= $pedaco;
        }

        return new self($bytes);
    }

    public function paginas(): int
    {
        return preg_match('#/Type /Pages /Kids \[[^\]]*\] /Count (\d+)#', $this->bytes, $m) === 1 ? (int) $m[1] : 0;
    }

    public function titulo(): ?string
    {
        return preg_match('#/Title \((.*?)\) /Producer \(#', $this->bytes, $m) === 1 ? $m[1] : null;
    }

    public function produtor(): ?string
    {
        return preg_match('#/Producer \((.*?)\) >>#', $this->bytes, $m) === 1 ? $m[1] : null;
    }

    /** @return list<string> */
    public function jpegs(): array
    {
        preg_match_all("#/Filter /DCTDecode /Length (\\d+) >>\nstream\n#", $this->bytes, $m, \PREG_OFFSET_CAPTURE);
        $jpegs = [];
        foreach ($m[0] as $i => [$cabecalho, $posicao]) {
            $jpegs[] = substr($this->bytes, $posicao + \strlen($cabecalho), (int) $m[1][$i][0]);
        }

        return $jpegs;
    }

    /**
     * O `/Width` e o `/Height` de cada imagem, na ordem das páginas.
     *
     * @return list<array{int, int}>
     */
    public function medidasDasImagens(): array
    {
        preg_match_all('#/Subtype /Image /Width (\d+) /Height (\d+) #', $this->bytes, $m, \PREG_SET_ORDER);

        return array_map(static fn (array $imagem): array => [(int) $imagem[1], (int) $imagem[2]], $m);
    }

    /**
     * As tabelas de quantização (segmentos DQT) de uma JPEG, até o início dos dados (SOS). Com a mesma libjpeg, elas
     * só dependem da qualidade: duas JPEGs de mesma qualidade têm as mesmas tabelas.
     */
    public static function quantizacao(string $jpeg): string
    {
        $tabelas = '';
        $posicao = 2;
        while ($posicao + 4 <= \strlen($jpeg) && $jpeg[$posicao] === "\xFF" && $jpeg[$posicao + 1] !== "\xDA") {
            $tamanho = (int) (unpack('n', substr($jpeg, $posicao + 2, 2)) ?: [1 => 0])[1];
            if ($tamanho < 2) {
                break;
            }
            if ($jpeg[$posicao + 1] === "\xDB") {
                $tabelas .= substr($jpeg, $posicao + 4, $tamanho - 2);
            }
            $posicao += 2 + $tamanho;
        }

        return $tabelas;
    }

    /** As tabelas de quantização de uma JPEG qualquer, gerada pela mesma GD na qualidade pedida. */
    public static function quantizacaoDaQualidade(int $qualidade): string
    {
        $imagem = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($imagem, null, $qualidade);
        $jpeg = (string) ob_get_clean();
        imagedestroy($imagem);

        return self::quantizacao($jpeg);
    }

    public function imagem(int $pagina): \GdImage
    {
        $imagem = imagecreatefromstring($this->jpegs()[$pagina - 1]);
        if ($imagem === false) {
            throw new \RuntimeException('JPEG ilegível na página ' . $pagina);
        }

        return $imagem;
    }

    /**
     * Média de cada canal num retângulo dado em unidades do desenho (W = 1000, escala 2,48).
     *
     * @return array{float, float, float}
     */
    public static function media(\GdImage $imagem, float $x, float $y, float $largura, float $altura): array
    {
        $soma = [0, 0, 0];
        $n = 0;
        for ($py = (int) ($y * 2.48); $py < (int) (($y + $altura) * 2.48); $py += 2) {
            for ($px = (int) ($x * 2.48); $px < (int) (($x + $largura) * 2.48); $px += 2) {
                $cor = imagecolorat($imagem, $px, $py);
                $soma[0] += ($cor >> 16) & 0xFF;
                $soma[1] += ($cor >> 8) & 0xFF;
                $soma[2] += $cor & 0xFF;
                ++$n;
            }
        }

        return [$soma[0] / $n, $soma[1] / $n, $soma[2] / $n];
    }

    /** O pixel mais escuro (menor soma dos canais / 3) do retângulo, em unidades do desenho. */
    public static function maisEscuro(\GdImage $imagem, float $x, float $y, float $largura, float $altura): float
    {
        $menor = 255.0;
        for ($py = (int) ($y * 2.48); $py < (int) (($y + $altura) * 2.48); $py += 2) {
            for ($px = (int) ($x * 2.48); $px < (int) (($x + $largura) * 2.48); $px += 2) {
                $cor = imagecolorat($imagem, $px, $py);
                $menor = min($menor, ((($cor >> 16) & 0xFF) + (($cor >> 8) & 0xFF) + ($cor & 0xFF)) / 3);
            }
        }

        return $menor;
    }
}
