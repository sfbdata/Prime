<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDoComprovante;

/**
 * O selo holográfico do pé do comprovante (desenho 16 L588 e L591 a L593): estrela de 64 pontas com degradê em
 * volta, 36 elipses giradas, o "visto" verde, o anel branco com "BLUEJUS AUTENTICO CÓDIGO" e "BLUEJUS ·" por dentro.
 */
final class SeloHolografico
{
    private const RAIO = 58;
    private const IRIDESCENTE = ['#7fd0e8', '#b4e7a8', '#f4e08a', '#f2b0c8', '#b7a6f0', '#7fd0e8'];
    private const AZUL_ESCURO = '#0b3550';

    private float $cx = 0.0;
    private float $cy = 0.0;
    private float $k = 1.0;

    public function __construct(
        private readonly TelaDoComprovante $tela,
        private readonly SementeDoDesenho $sorteio,
    ) {
    }

    public function desenhar(float $cx, float $cy, float $escala, string $codigo): void
    {
        [$this->cx, $this->cy, $this->k] = [$cx, $cy, $escala];
        $this->estrela();
        $this->elipses();
        $this->tela->circulo($cx, $cy, 32 * $escala, $this->tela->cor('#ffffff', .84));
        $this->tela->linhas([$this->ponto(-15, 0), $this->ponto(-4, 12), $this->ponto(16, -12)], 5 * $escala, $this->tela->cor('#1e7b45'));
        $this->anelBranco(self::RAIO * .72, self::RAIO * .83);
        $semente = 7;
        foreach (str_split($codigo) as $i => $letra) {
            $semente += ord($letra) * ($i + 3);
        }
        $this->textoEmAnel('BLUEJUS AUTENTICO ' . $codigo . ' ', self::RAIO * .745, 'normal', self::RAIO * .088, $semente * .137);
        $this->textoEmAnel('BLUEJUS · ', self::RAIO * .405, 'negrito', self::RAIO * .15, null);
    }

    /** A estrela de 64 pontas com o degradê que dá a volta (o `createConicGradient` do desenho), em fatias finas. */
    private function estrela(): void
    {
        $inicio = $this->sorteio->proximo() * 6;
        $vertices = [];
        for ($i = 0; $i < 64; ++$i) {
            $a = $i / 64 * M_PI * 2;
            $r = $i % 2 !== 0 ? self::RAIO : self::RAIO * .914;
            $vertices[] = [$r * cos($a), $r * sin($a)];
        }
        for ($i = 0; $i < 64; ++$i) {
            [$a, $b] = [$vertices[$i], $vertices[($i + 1) % 64]];
            for ($parte = 0; $parte < 4; ++$parte) {
                $p = [$a[0] + ($b[0] - $a[0]) * $parte / 4, $a[1] + ($b[1] - $a[1]) * $parte / 4];
                $q = [$a[0] + ($b[0] - $a[0]) * ($parte + 1) / 4, $a[1] + ($b[1] - $a[1]) * ($parte + 1) / 4];
                $angulo = fmod(atan2(($p[1] + $q[1]) / 2, ($p[0] + $q[0]) / 2) - $inicio + 8 * M_PI, 2 * M_PI);
                [$r, $g, $bl] = Degrade::cor(self::IRIDESCENTE, $angulo / (2 * M_PI));
                $this->tela->poligono([$this->ponto(0, 0), $this->ponto($p[0], $p[1]), $this->ponto($q[0], $q[1])], $this->tela->corRgb($r, $g, $bl));
            }
        }
    }

    /** 36 elipses giradas, finas, em azul translúcido (L588). */
    private function elipses(): void
    {
        $cor = $this->tela->cor(self::AZUL_ESCURO, .4);
        for ($j = 0; $j < 36; ++$j) {
            $giro = $j / 36 * M_PI * 2;
            $pontos = [];
            for ($i = 0; $i <= 72; ++$i) {
                $u = $i / 72 * M_PI * 2;
                [$ex, $ey] = [(self::RAIO - 8) * cos($u), 17 * sin($u)];
                $pontos[] = $this->ponto($ex * cos($giro) - $ey * sin($giro), $ex * sin($giro) + $ey * cos($giro));
            }
            $this->tela->linhas($pontos, .45 * $this->k, $cor);
        }
    }

    private function anelBranco(float $dentro, float $fora): void
    {
        $cor = $this->tela->cor('#ffffff', .76);
        for ($i = 0; $i < 96; ++$i) {
            [$a, $b] = [$i / 96 * M_PI * 2, ($i + 1) / 96 * M_PI * 2];
            $this->tela->poligono([
                $this->ponto($fora * cos($a), $fora * sin($a)), $this->ponto($fora * cos($b), $fora * sin($b)),
                $this->ponto($dentro * cos($b), $dentro * sin($b)), $this->ponto($dentro * cos($a), $dentro * sin($a)),
            ], $cor);
        }
    }

    /**
     * Texto que dá a volta no anel (o `anelTxt` do desenho, L592): o texto se repete até fechar a volta e cada letra
     * fica de pé, tangente ao círculo. Sem início dado, "BLUEJUS" fica centrado no alto.
     */
    private function textoEmAnel(string $base, float $raio, string $fonte, float $tamanho, ?float $inicio): void
    {
        $voltas = max(1, (int) round(2 * M_PI * $raio / max(.1, $this->largura($base, $fonte, $tamanho))));
        $letras = mb_str_split(str_repeat($base, $voltas));
        $larguras = array_map(fn (string $letra): float => $this->largura($letra, $fonte, $tamanho), $letras);
        $passo = 2 * M_PI / array_sum($larguras);
        $a = $inicio ?? -M_PI / 2 - $this->largura('BLUEJUS', $fonte, $tamanho) * $passo / 2;
        $cor = $this->tela->cor(self::AZUL_ESCURO);
        foreach ($letras as $i => $letra) {
            $meio = $a + $larguras[$i] * $passo / 2;
            $giro = $meio + M_PI / 2;
            $metade = $larguras[$i] / 2;
            [$x, $y] = $this->ponto($raio * cos($meio) - $metade * cos($giro), $raio * sin($meio) - $metade * sin($giro));
            $this->tela->texto($letra, $fonte, $tamanho * $this->k, $x, $y, $cor, rad2deg(-$giro));
            $a += $larguras[$i] * $passo;
        }
    }

    /** Largura nas medidas do selo (antes da escala dele). */
    private function largura(string $texto, string $fonte, float $tamanho): float
    {
        return $this->tela->largura($texto, $fonte, $tamanho * $this->k) / $this->k;
    }

    /** @return array{float, float} o ponto do selo nas medidas da folha */
    private function ponto(float $x, float $y): array
    {
        return [$this->cx + $x * $this->k, $this->cy + $y * $this->k];
    }
}
