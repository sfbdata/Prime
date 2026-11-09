<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDoComprovante;

/**
 * A folha de desenho do comprovante em GD: as medidas são as do desenho (1000 x 1480, L551) e a escala as leva aos
 * pixels da imagem (2,48 no desenho: 2480 x 3670). Cores em hexadecimal com opacidade, como o `rgba` do desenho.
 *
 * O GD mede a fonte em pontos a 96 dpi: o tamanho em pixels do desenho vira pontos com 0,75.
 */
final class TelaDoComprovante
{
    public const LARGURA = 1000;
    public const ALTURA = 1480;
    private const PONTOS_POR_PIXEL = 0.75;
    private const QUALIDADE_JPEG = 93;

    private \GdImage $imagem;

    /** @param array<string, string> $fontes nome da fonte => caminho do TTF */
    public function __construct(
        private readonly float $escala,
        private readonly array $fontes,
    ) {
        $imagem = imagecreatetruecolor($this->px(self::LARGURA), $this->px(self::ALTURA));
        if ($imagem === false) {
            throw new \RuntimeException('Não foi possível criar a imagem do comprovante.');
        }
        imagealphablending($imagem, true);
        $this->imagem = $imagem;
    }

    public function px(float $medida): int
    {
        return (int) round($medida * $this->escala);
    }

    /** Cor do GD a partir de `#rrggbb` e da opacidade do desenho (1 = opaca). */
    public function cor(string $hex, float $opacidade = 1.0): int
    {
        [$r, $g, $b] = self::rgb($hex);

        return $this->corRgb($r, $g, $b, $opacidade);
    }

    public function corRgb(int $r, int $g, int $b, float $opacidade = 1.0): int
    {
        $alfa = (int) round((1 - max(0.0, min(1.0, $opacidade))) * 127);

        return (int) imagecolorallocatealpha($this->imagem, $r, $g, $b, $alfa);
    }

    /** @return array{int, int, int} */
    public static function rgb(string $hex): array
    {
        $valor = (int) hexdec(ltrim($hex, '#'));

        return [($valor >> 16) & 0xFF, ($valor >> 8) & 0xFF, $valor & 0xFF];
    }

    public function retangulo(float $x, float $y, float $largura, float $altura, int $cor): void
    {
        imagefilledrectangle($this->imagem, $this->px($x), $this->px($y), max($this->px($x), $this->px($x + $largura) - 1), max($this->px($y), $this->px($y + $altura) - 1), $cor);
    }

    /** Contorno de retângulo com a espessura centrada na linha, como o `strokeRect` do canvas. */
    public function contorno(float $x, float $y, float $largura, float $altura, float $espessura, int $cor): void
    {
        $meia = $espessura / 2;
        $this->retangulo($x - $meia, $y - $meia, $largura + $espessura, $espessura, $cor);
        $this->retangulo($x - $meia, $y + $altura - $meia, $largura + $espessura, $espessura, $cor);
        $this->retangulo($x - $meia, $y + $meia, $espessura, $altura - $espessura, $cor);
        $this->retangulo($x + $largura - $meia, $y + $meia, $espessura, $altura - $espessura, $cor);
    }

    /** @param list<array{float, float}> $pontos linha aberta que passa pelos pontos */
    public function linhas(array $pontos, float $espessura, int $cor): void
    {
        imagesetthickness($this->imagem, max(1, $this->px($espessura)));
        for ($i = 1, $total = count($pontos); $i < $total; ++$i) {
            imageline($this->imagem, $this->px($pontos[$i - 1][0]), $this->px($pontos[$i - 1][1]), $this->px($pontos[$i][0]), $this->px($pontos[$i][1]), $cor);
        }
        imagesetthickness($this->imagem, 1);
    }

    /** @param list<array{float, float}> $pontos polígono preenchido (três pontos ou mais) */
    public function poligono(array $pontos, int $cor): void
    {
        $plano = [];
        foreach ($pontos as [$x, $y]) {
            $plano[] = $this->px($x);
            $plano[] = $this->px($y);
        }
        imagefilledpolygon($this->imagem, $plano, $cor);
    }

    public function circulo(float $cx, float $cy, float $raio, int $cor): void
    {
        imagefilledellipse($this->imagem, $this->px($cx), $this->px($cy), $this->px($raio * 2), $this->px($raio * 2), $cor);
    }

    /**
     * Texto com a linha de base em (x, y). O ângulo é o do GD (graus, anti-horário). `centro` centraliza em x.
     */
    public function texto(string $texto, string $fonte, float $tamanho, float $x, float $y, int $cor, float $angulo = 0.0, bool $centro = false): void
    {
        if ($centro) {
            $x -= $this->largura($texto, $fonte, $tamanho) / 2;
        }
        imagettftext($this->imagem, $tamanho * $this->escala * self::PONTOS_POR_PIXEL, $angulo, $this->px($x), $this->px($y), $cor, $this->fontes[$fonte], $texto);
    }

    /** Texto com a linha de base em (x, y), de cor em degradê de cima para baixo entre `topo` e `base` (L572). */
    public function textoEmDegrade(string $texto, string $fonte, float $tamanho, float $x, float $y, float $altura, string $topo, string $base): void
    {
        $faixas = 24;
        [$r0, $g0, $b0] = self::rgb($topo);
        [$r1, $g1, $b1] = self::rgb($base);
        for ($i = 0; $i < $faixas; ++$i) {
            $t = $i / ($faixas - 1);
            imagesetclip($this->imagem, 0, $this->px($y - $altura + $altura * $i / $faixas), $this->px(self::LARGURA), $this->px($y - $altura + $altura * ($i + 1) / $faixas + 2));
            $cor = $this->corRgb((int) round($r0 + ($r1 - $r0) * $t), (int) round($g0 + ($g1 - $g0) * $t), (int) round($b0 + ($b1 - $b0) * $t));
            $this->texto($texto, $fonte, $tamanho, $x, $y, $cor);
        }
        imagesetclip($this->imagem, 0, 0, $this->px(self::LARGURA) - 1, $this->px(self::ALTURA) - 1);
    }

    /** Largura do texto nas medidas do desenho. */
    public function largura(string $texto, string $fonte, float $tamanho): float
    {
        $caixa = imagettfbbox($tamanho * $this->escala * self::PONTOS_POR_PIXEL, 0, $this->fontes[$fonte], $texto);

        return $caixa === false ? 0.0 : ($caixa[2] - $caixa[0]) / $this->escala;
    }

    /**
     * Retângulo em degradê, de faixa em faixa de pixel: na horizontal (da esquerda para a direita) ou na vertical.
     *
     * @param list<string> $paradas cores igualmente espaçadas, como o `addColorStop(i / n)` do desenho
     */
    public function degrade(float $x, float $y, float $largura, float $altura, array $paradas, bool $horizontal): void
    {
        $passos = max(1, $this->px($horizontal ? $largura : $altura));
        for ($i = 0; $i < $passos; ++$i) {
            [$r, $g, $b] = Degrade::cor($paradas, $i / max(1, $passos - 1));
            $cor = $this->corRgb($r, $g, $b);
            if ($horizontal) {
                imageline($this->imagem, $this->px($x) + $i, $this->px($y), $this->px($x) + $i, $this->px($y + $altura) - 1, $cor);
                continue;
            }
            imageline($this->imagem, $this->px($x), $this->px($y) + $i, $this->px($x + $largura) - 1, $this->px($y) + $i, $cor);
        }
    }

    /**
     * Fundo inteiro em degradê na diagonal (do canto de cima à esquerda ao de baixo à direita, L555): desenhado numa
     * imagem pequena e ampliado com suavização, o que no GD sai bem mais barato que pixel a pixel.
     *
     * @param list<string> $paradas
     */
    public function fundoDiagonal(array $paradas): void
    {
        [$largura, $altura] = [50, 74];
        $pequena = imagecreatetruecolor($largura, $altura);
        if ($pequena === false) {
            throw new \RuntimeException('Não foi possível criar o fundo do comprovante.');
        }
        $norma = self::LARGURA ** 2 + self::ALTURA ** 2;
        for ($y = 0; $y < $altura; ++$y) {
            for ($x = 0; $x < $largura; ++$x) {
                $t = (($x / ($largura - 1)) * self::LARGURA ** 2 + ($y / ($altura - 1)) * self::ALTURA ** 2) / $norma;
                [$r, $g, $b] = Degrade::cor($paradas, $t);
                imagesetpixel($pequena, $x, $y, (int) imagecolorallocate($pequena, $r, $g, $b));
            }
        }
        imagecopyresampled($this->imagem, $pequena, 0, 0, 0, 0, imagesx($this->imagem), imagesy($this->imagem), $largura, $altura);
    }

    /**
     * JPEG com qualidade 93, como o `toDataURL('image/jpeg', .93)` do desenho (L589). O codificador do GD escreve linha a
     * linha; o de PNG montaria uma cópia inteira da imagem (uns 30 MB a mais nesta escala).
     */
    public function jpeg(): string
    {
        ob_start();
        imagejpeg($this->imagem, null, self::QUALIDADE_JPEG);

        return (string) ob_get_clean();
    }
}
