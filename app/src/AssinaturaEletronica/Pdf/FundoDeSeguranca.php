<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * O fundo de segurança de uma página (`fundo(g, pn)` do desenho, dc:409-410), nas oito camadas e na ordem do
 * desenho: gradiente íris, linhas onduladas, rosácea, marca d'água, molduras, borda ondulada, microtexto e faixa
 * lateral. A fase das ondas e a forma da rosácea saem do {@see Sorteio} da página: cada página tem o seu desenho.
 */
final class FundoDeSeguranca
{
    private const NV = '#0b3550';
    private const IRIS = ['#f4f9fc', '#f5fbf6', '#fdf9f0', '#f8f4fc', '#f4f9fc'];
    private const FAIXA = ['#7fb4cf', '#d9eef7', '#8fcaa5', '#e8dca8', '#7fb4cf'];
    private const FIOS_DA_BORDA = [[0.0, '#0a6fa0'], [2.1, '#1e7b45'], [4.2, '#8a6d1f']];
    private const FATOR_DA_BORDA = 3;

    public function desenhar(Tela $tela, string $hash, int $pagina, string $codigo): void
    {
        $sorteio = Sorteio::daPagina($hash, $pagina);
        $this->iris($tela);

        $fase = $sorteio->proximo() * 6;
        $frequencia1 = .012 + $sorteio->proximo() * .012;
        $frequencia2 = .03 + $sorteio->proximo() * .02;
        $this->ondas($tela, $fase, $frequencia1, $frequencia2);

        $giro = $sorteio->proximo() * 3;
        $petalas = 9 + (int) floor($sorteio->proximo() * 5);
        $this->rosacea($tela, $giro, $petalas);

        $this->marcaDagua($tela, $codigo);
        $tela->contorno(12, 12, Tela::W - 24, Tela::H - 24, 2, Cor::hex(self::NV));
        $tela->contorno(34, 34, Tela::W - 68, Tela::H - 68, .8, Cor::hex(self::NV));
        $this->borda($tela);
        $this->microtexto($tela, $codigo);
        $this->faixaLateral($tela);
    }

    private function iris(Tela $tela): void
    {
        $paradas = array_map(static fn (string $hex): Cor => Cor::hex($hex), self::IRIS);
        $tela->gradiente(0, 0, Tela::W, Tela::H, [0.0, 0.0], [Tela::W, Tela::H], $paradas, 5.0);
    }

    private function ondas(Tela $tela, float $fase, float $frequencia1, float $frequencia2): void
    {
        $impar = Cor::hex('#0a6fa0', .11);
        $par = Cor::hex('#1e7b45', .085);

        for ($k = -2; $k < 64; ++$k) {
            $y0 = $k * Tela::H / 60;
            $pontos = [];
            for ($x = 0; $x <= Tela::W; $x += 5) {
                $pontos[] = [(float) $x, $y0 + 13 * sin($x * $frequencia1 + $fase + $k * .32) + 5 * sin($x * $frequencia2 + $k * .7)];
            }
            $tela->linha($pontos, .5, $k % 2 !== 0 ? $impar : $par);
        }
    }

    private function rosacea(Tela $tela, float $giro, int $petalas): void
    {
        $cor = Cor::hex(self::NV, .05);

        for ($j = 0; $j < 34; ++$j) {
            $a0 = $j / 34 * M_PI * 2;
            $pontos = [];
            for ($i = 0; $i <= 200; ++$i) {
                $t = $i / 200 * M_PI * 2;
                $r = 320 * (.66 + .34 * sin($petalas * $t + $giro));
                $pontos[] = [500 + $r * cos($t + $a0), 720 + $r * sin($t + $a0)];
            }
            $tela->linha($pontos, .45, $cor, fechar: true);
        }
    }

    /**
     * translate(500, 707), rotate(-π / 6,4), negrito 18 a 4%, linhas de -1050 a 1050 de 62 em 62. Cada linha tem
     * mais de 3000 de comprimento; as letras que caem inteiras fora da folha (além de 800 do centro, no eixo girado,
     * sendo que a folha chega a 774) não são desenhadas: a imagem é a mesma e a página sai mais rápido.
     */
    private function marcaDagua(Tela $tela, string $codigo): void
    {
        $giro = -M_PI / 6.4;
        $cor = Cor::hex(self::NV, .04);
        $letras = mb_str_split(str_repeat(sprintf(TextosDaFolha::MARCA_DAGUA, $codigo), 9));

        for ($yy = -1050, $i = 0; $yy < 1050; $yy += 62, ++$i) {
            $lx = -1400 + ($i % 2) * 80;
            $fim = $lx;
            $trecho = '';
            foreach ($letras as $letra) {
                $avanco = MedidorDeTexto::largura($letra, Fonte::SansNegrito, 18);
                if ($trecho === '' && $fim + $avanco < -800) {
                    $lx = $fim += $avanco;
                    continue;
                }
                if ($fim > 800) {
                    break;
                }
                $trecho .= $letra;
                $fim += $avanco;
            }
            $x = 500 + $lx * cos($giro) - $yy * sin($giro);
            $y = 707 + $lx * sin($giro) + $yy * cos($giro);
            $tela->texto($trecho, $x, $y, Fonte::SansNegrito, 18, $cor, Alinhamento::Esquerda, $giro);
        }
    }

    /**
     * Três fios (fases 0, 2,1 e 4,2) de amplitude 8 ao longo do retângulo em 23, um lado por vez, cada lado desenhado
     * num recorte ampliado (o fio de 0,7 sai liso como no canvas, sem os degraus da linha grossa da GD).
     */
    private function borda(Tela $tela): void
    {
        $w = Tela::W - 23;
        $h = Tela::H - 23;
        foreach ([[23, 23, $w, 23], [$w, 23, $w, $h], [$w, $h, 23, $h], [23, $h, 23, 23]] as [$x0, $y0, $x1, $y1]) {
            $folga = 10;
            $recorte = $tela->ampliar(min($x0, $x1) - $folga, min($y0, $y1) - $folga, abs($x1 - $x0) + 2 * $folga, abs($y1 - $y0) + 2 * $folga, self::FATOR_DA_BORDA);
            try {
                $this->fiosDaBorda($recorte, $x0, $y0, $x1, $y1);
            } finally {
                $recorte->recolher();
            }
        }
    }

    private function fiosDaBorda(Tela $tela, float $x0, float $y0, float $x1, float $y1): void
    {
        $comprimento = hypot($x1 - $x0, $y1 - $y0);
        $ux = ($x1 - $x0) / $comprimento;
        $uy = ($y1 - $y0) / $comprimento;
        foreach (self::FIOS_DA_BORDA as [$fase, $hex]) {
            $pontos = [];
            for ($s = 0; $s <= $comprimento; $s += 2) {
                $o = 8 * sin($s * .1 + $fase);
                $pontos[] = [$x0 + $ux * $s - $uy * $o, $y0 + $uy * $s + $ux * $o];
            }
            $tela->linha($pontos, .7, Cor::hex($hex));
        }
    }

    /** Repetido nove vezes e cortado no último caractere que cabe em 890 (o `while … slice(0, -1)` do desenho). */
    private function microtexto(Tela $tela, string $codigo): void
    {
        $cabe = '';
        $largura = 0.0;
        foreach (mb_str_split(str_repeat(sprintf(TextosDaFolha::MICROTEXTO, $codigo), 9)) as $letra) {
            $largura += MedidorDeTexto::largura($letra, Fonte::Sans, 5);
            if ($largura > 890) {
                break;
            }
            $cabe .= $letra;
        }

        $tela->texto($cabe, 46, Tela::H - 39, Fonte::Sans, 5, Cor::hex(self::NV));
    }

    /** translate(945, 48): a cada 100, retângulo 12 x 62 em gradiente e "BLUEJUS ASSINA" girado -90 graus. */
    private function faixaLateral(Tela $tela): void
    {
        $paradas = array_map(static fn (string $hex): Cor => Cor::hex($hex), self::FAIXA);

        for ($yy = 0; $yy + 74 < Tela::H - 96; $yy += 100) {
            $topo = 48 + $yy;
            $tela->gradiente(945, $topo + 8, 12, 62, [945.0, (float) $topo], [957.0, $topo + 62.0], $paradas, 1 / Tela::ESCALA);
            $tela->texto(TextosDaFolha::FAIXA, 945 + 8.5, $topo + 66, Fonte::SansNegrito, 6.5, Cor::hex(self::NV), Alinhamento::Esquerda, -M_PI / 2);
        }
    }
}
