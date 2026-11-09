<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDoComprovante;

/**
 * Os padrões de segurança do fundo do comprovante (desenho 16 L553 a L569), na ordem do desenho: fundo iridescente,
 * linhas onduladas, rosáceas, símbolo grande, marca d'água diagonal com o código e o nome, moldura dupla, bordas
 * onduladas em três cores, microtexto e faixa holográfica vertical. Medidas e cores do próprio desenho.
 */
final class PadroesDeSeguranca
{
    private const W = TelaDoComprovante::LARGURA;
    private const H = TelaDoComprovante::ALTURA;
    private const AZUL_ESCURO = '#0b3550';
    private const HOLOGRAFICO = ['#7fb4cf', '#d9eef7', '#8fcaa5', '#e8dca8', '#7fb4cf'];

    public function __construct(
        private readonly TelaDoComprovante $tela,
        private readonly SementeDoDesenho $sorteio,
    ) {
    }

    public function desenhar(string $codigo, string $nome): void
    {
        $this->tela->fundoDiagonal(['#e8f3fa', '#ebf7ee', '#fbf4e6', '#f2ecf9', '#e8f3fa']);
        $this->ondas();
        $this->rosacea(770, 245, 150, 7 + (int) floor($this->sorteio->proximo() * 4), 26, $this->tela->cor('#0a6fa0', .17), .55);
        $this->rosacea(500, 790, 340, 9 + (int) floor($this->sorteio->proximo() * 5), 34, $this->tela->cor(self::AZUL_ESCURO, .075), .55);
        $this->rosacea(205, 1240, 118, 5 + (int) floor($this->sorteio->proximo() * 4), 22, $this->tela->cor('#1e7b45', .17), .5);
        (new SimboloBlueJus($this->tela))->desenhar(500, 790, 500, $this->tela->cor(self::AZUL_ESCURO, .08), $this->tela->cor('#0a6fa0', .08), 34);
        $this->marcaDagua($codigo, $nome);
        $this->tela->contorno(24, 24, self::W - 48, self::H - 48, 3, $this->tela->cor(self::AZUL_ESCURO));
        $this->tela->contorno(56, 56, self::W - 112, self::H - 112, 1, $this->tela->cor(self::AZUL_ESCURO));
        $this->bordasOnduladas();
        $this->microtexto($codigo);
        $this->faixaVertical();
    }

    /** Linhas onduladas de fundo, alternando azul e verde (L556). */
    private function ondas(): void
    {
        $fase = $this->sorteio->proximo() * 6;
        $f1 = .012 + $this->sorteio->proximo() * .012;
        $f2 = .03 + $this->sorteio->proximo() * .02;
        $azul = $this->tela->cor('#0a6fa0', .17);
        $verde = $this->tela->cor('#1e7b45', .13);
        for ($k = -2; $k < 62; ++$k) {
            $y0 = $k * self::H / 58;
            $pontos = [];
            for ($x = 0; $x <= self::W; $x += 5) {
                $pontos[] = [(float) $x, $y0 + 15 * sin($x * $f1 + $fase + $k * .32) + 6 * sin($x * $f2 + $k * .7)];
            }
            $this->tela->linhas($pontos, .7, $k % 2 !== 0 ? $azul : $verde);
        }
    }

    /** Rosácea de `n` curvas com `m` pétalas (L558). */
    private function rosacea(float $cx, float $cy, float $raio, int $petalas, int $curvas, int $cor, float $espessura): void
    {
        $fase = $this->sorteio->proximo() * 3;
        for ($j = 0; $j < $curvas; ++$j) {
            $a0 = $j / $curvas * M_PI * 2;
            $pontos = [];
            for ($i = 0; $i <= 240; ++$i) {
                $t = $i / 240 * M_PI * 2;
                $r = $raio * (.66 + .34 * sin($petalas * $t + $fase));
                $pontos[] = [$cx + $r * cos($t + $a0), $cy + $r * sin($t + $a0)];
            }
            $this->tela->linhas($pontos, $espessura, $cor);
        }
    }

    /** "BLUEJUS · CÓDIGO · NOME · " em linhas diagonais por toda a folha (L563). */
    private function marcaDagua(string $codigo, string $nome): void
    {
        $angulo = -M_PI / 6.4;
        $linha = str_repeat('BLUEJUS · ' . $codigo . ' · ' . mb_strtoupper($nome) . ' · ', 5);
        $cor = $this->tela->cor(self::AZUL_ESCURO, .065);
        for ($y = -1100, $i = 0; $y < 1100; $y += 74, ++$i) {
            $x = -1500 + ($i % 2) * 90;
            $this->tela->texto($linha, 'negrito', 21, self::W / 2 + $x * cos($angulo) - $y * sin($angulo), self::H / 2 + $x * sin($angulo) + $y * cos($angulo), $cor, rad2deg(-$angulo));
        }
    }

    /** Três senoides por lado, em azul, verde e ocre (L565). */
    private function bordasOnduladas(): void
    {
        $lados = [[40, 40, self::W - 40, 40], [self::W - 40, 40, self::W - 40, self::H - 40], [self::W - 40, self::H - 40, 40, self::H - 40], [40, self::H - 40, 40, 40]];
        foreach ($lados as [$x0, $y0, $x1, $y1]) {
            $comprimento = hypot($x1 - $x0, $y1 - $y0);
            [$ux, $uy] = [($x1 - $x0) / $comprimento, ($y1 - $y0) / $comprimento];
            foreach ([[0, '#0a6fa0'], [2.1, '#1e7b45'], [4.2, '#8a6d1f']] as [$fase, $hex]) {
                $pontos = [];
                for ($s = 0; $s <= $comprimento; $s += 2) {
                    $o = 12 * sin($s * .085 + $fase);
                    $pontos[] = [$x0 + $ux * $s - $uy * $o, $y0 + $uy * $s + $ux * $o];
                }
                $this->tela->linhas($pontos, .9, $this->tela->cor($hex));
            }
        }
    }

    /** Microtexto de 7 px no alto e no pé da moldura, cortado em 830 de largura (L567). */
    private function microtexto(string $codigo): void
    {
        $texto = str_repeat('BLUEJUS PONTO ELETRONICO COMPROVANTE AUTENTICO ' . $codigo . ' ', 6);
        $texto = $this->cortarNaLargura($texto, 'normal', 7, 830);
        $cor = $this->tela->cor(self::AZUL_ESCURO);
        $this->tela->texto($texto, 'normal', 7, 66, 70, $cor);
        $this->tela->texto($texto, 'normal', 7, 66, self::H - 62, $cor);
    }

    /** Blocos holográficos com "BLUEJUS PONTO" de baixo para cima, à direita (L568). */
    private function faixaVertical(): void
    {
        $cor = $this->tela->cor(self::AZUL_ESCURO);
        for ($y = 0; $y + 84 < self::H - 108; $y += 112) {
            $this->tela->degrade(910, 54 + $y + 12, 16, 72, self::HOLOGRAFICO, false);
            $this->tela->texto('BLUEJUS PONTO', 'negrito', 8, 921, 54 + $y + 80, $cor, 90.0);
        }
    }

    private function cortarNaLargura(string $texto, string $fonte, float $tamanho, float $limite): string
    {
        [$menor, $maior] = [0, mb_strlen($texto)];
        while ($menor < $maior) {
            $meio = intdiv($menor + $maior + 1, 2);
            if ($this->tela->largura(mb_substr($texto, 0, $meio), $fonte, $tamanho) <= $limite) {
                $menor = $meio;
                continue;
            }
            $maior = $meio - 1;
        }

        return mb_substr($texto, 0, $menor);
    }
}
