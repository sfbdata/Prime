<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * O selo holográfico do rodapé (`selo` em dc:412 e `seloAneis`/`anelTxt` em dc:313-314): estrela de 64 pontas em
 * gradiente cônico, 36 elipses giradas, disco branco, visto verde (concluído) ou "PENDENTE", anel branco e dois anéis
 * de texto com o código do documento.
 *
 * Desenhado num recorte quatro vezes maior e reduzido de volta ({@see Tela::ampliar()}): é o antisserrilhado que o
 * canvas tem e a GD não. O cônico é pintado em fatias finas a partir do centro, cada uma na cor do seu ângulo.
 */
final class Selo
{
    private const FATOR = 4;
    private const NV = '#0b3550';
    private const HOLOGRAMA = ['#7fd0e8', '#b4e7a8', '#f4e08a', '#f2b0c8', '#b7a6f0', '#7fd0e8'];
    private const INICIO_DO_CONICO = 1.2;
    private const FATIAS_POR_PONTA = 4;

    public function desenhar(Tela $pagina, float $cx, float $cy, float $sr, string $codigo, bool $concluido): void
    {
        $tela = $pagina->ampliar($cx - $sr - 2, $cy - $sr - 2, 2 * $sr + 4, 2 * $sr + 4, self::FATOR);

        try {
            $this->estrela($tela, $cx, $cy, $sr);
            $this->elipses($tela, $cx, $cy, $sr);
            $tela->poligono(self::circulo($cx, $cy, $sr * .55), Cor::hex('#ffffff', .85));
            if ($concluido) {
                $this->visto($tela, $cx, $cy, $sr);
            } else {
                $tela->texto(TextosDaFolha::PENDENTE, $cx, $cy + $sr * .04, Fonte::SansNegrito, $sr * .112, Cor::hex('#8a6200'), Alinhamento::Centro);
            }
            $this->aneis($tela, $cx, $cy, $sr, $codigo);
        } finally {
            $tela->recolher();
        }
    }

    private function estrela(Tela $tela, float $cx, float $cy, float $sr): void
    {
        $pontas = [];
        for ($i = 0; $i <= 64; ++$i) {
            $a = $i / 64 * M_PI * 2;
            $r = $i % 2 !== 0 ? $sr : $sr * .914;
            $pontas[] = [$cx + $r * cos($a), $cy + $r * sin($a)];
        }

        $paradas = array_map(static fn (string $hex): Cor => Cor::hex($hex), self::HOLOGRAMA);
        $tela->poligono(\array_slice($pontas, 0, 64), $paradas[0]);

        for ($i = 0; $i < 64; ++$i) {
            [$ax, $ay] = $pontas[$i];
            [$bx, $by] = $pontas[$i + 1];
            for ($f = 0; $f < self::FATIAS_POR_PONTA; ++$f) {
                $t0 = $f / self::FATIAS_POR_PONTA;
                $t1 = ($f + 1) / self::FATIAS_POR_PONTA;
                $p0 = [$ax + ($bx - $ax) * $t0, $ay + ($by - $ay) * $t0];
                $p1 = [$ax + ($bx - $ax) * $t1, $ay + ($by - $ay) * $t1];
                $meio = atan2(($p0[1] + $p1[1]) / 2 - $cy, ($p0[0] + $p1[0]) / 2 - $cx);
                $tela->poligono([[$cx, $cy], $p0, $p1], self::corDoConico($paradas, $meio));
            }
        }
    }

    /**
     * `createConicGradient(1.2, 0, 0)`: o ângulo corre no sentido horário da tela a partir de 1,2 rad.
     *
     * @param list<Cor> $paradas
     */
    private static function corDoConico(array $paradas, float $angulo): Cor
    {
        $volta = fmod($angulo - self::INICIO_DO_CONICO, 2 * M_PI);

        return Cor::noGradiente($paradas, ($volta < 0 ? $volta + 2 * M_PI : $volta) / (2 * M_PI));
    }

    /** 36 elipses (rx = sr - 8, ry = 0,3 sr) com traço de 0,45 a 40%: cada uma é um anel preenchido, sem emendas. */
    private function elipses(Tela $tela, float $cx, float $cy, float $sr): void
    {
        $cor = Cor::hex(self::NV, .4);
        $meio = .45 / 2;

        for ($j = 0; $j < 36; ++$j) {
            $giro = $j / 36 * M_PI * 2;
            $fora = self::elipse($cx, $cy, $sr - 8 + $meio, $sr * .3 + $meio, $giro);
            $dentro = array_reverse(self::elipse($cx, $cy, $sr - 8 - $meio, $sr * .3 - $meio, $giro));
            $tela->poligono(array_merge($fora, $dentro), $cor);
        }
    }

    /** Visto de (-0,26; 0) a (-0,07; 0,2) a (0,27; -0,2) vezes sr, traço 0,085 sr, ponta reta e canto em quina. */
    private function visto(Tela $tela, float $cx, float $cy, float $sr): void
    {
        $p = [[-.26 * $sr, 0.0], [-.07 * $sr, .2 * $sr], [.27 * $sr, -.2 * $sr]];
        $meia = .085 * $sr / 2;
        $n1 = self::normal($p[0], $p[1]);
        $n2 = self::normal($p[1], $p[2]);
        $quina = [$n1[0] + $n2[0], $n1[1] + $n2[1]];
        $tamanho = hypot($quina[0], $quina[1]);
        $quina = [$quina[0] / $tamanho, $quina[1] / $tamanho];
        $alcance = $meia / ($quina[0] * $n1[0] + $quina[1] * $n1[1]);

        $contorno = [];
        foreach ([[$p[0], $n1, $meia], [$p[1], $quina, $alcance], [$p[2], $n2, $meia]] as [$ponto, $direcao, $d]) {
            $contorno[] = [$cx + $ponto[0] + $direcao[0] * $d, $cy + $ponto[1] + $direcao[1] * $d];
        }
        foreach ([[$p[2], $n2, $meia], [$p[1], $quina, $alcance], [$p[0], $n1, $meia]] as [$ponto, $direcao, $d]) {
            $contorno[] = [$cx + $ponto[0] - $direcao[0] * $d, $cy + $ponto[1] - $direcao[1] * $d];
        }

        $tela->poligono($contorno, Cor::hex('#1e7b45'));
    }

    /** `seloAneis`: anel branco de 0,72 a 0,83 sr e os dois textos circulares. */
    private function aneis(Tela $tela, float $cx, float $cy, float $sr, string $codigo): void
    {
        $tela->poligono(array_merge(self::circulo($cx, $cy, $sr * .83), array_reverse(self::circulo($cx, $cy, $sr * .72))), Cor::hex('#ffffff', .76));

        $semente = 7;
        foreach (mb_str_split($codigo) as $i => $letra) {
            $semente += mb_ord($letra) * ($i + 3);
        }

        $this->anelDeTexto($tela, $cx, $cy, sprintf(TextosDaFolha::ANEL, $codigo), $sr * .745, Fonte::Sans, $sr * .088, $semente * .137);
        $this->anelDeTexto($tela, $cx, $cy, TextosDaFolha::ANEL_INTERNO, $sr * .405, Fonte::SansNegrito, $sr * .15, null);
    }

    /**
     * `anelTxt`: o texto repetido até fechar a volta, cada letra no seu ângulo; sem início dado, "BLUEJUS" fica
     * centrado no alto.
     */
    private function anelDeTexto(Tela $tela, float $cx, float $cy, string $base, float $r, Fonte $fonte, float $tamanho, ?float $inicio): void
    {
        $vezes = max(1, (int) round(2 * M_PI * $r / MedidorDeTexto::largura($base, $fonte, $tamanho)));
        $letras = mb_str_split(str_repeat($base, $vezes));
        $larguras = array_map(static fn (string $l): float => MedidorDeTexto::largura($l, $fonte, $tamanho), $letras);
        $escala = 2 * M_PI / array_sum($larguras);
        $a = $inicio ?? -M_PI / 2 - MedidorDeTexto::largura('BLUEJUS', $fonte, $tamanho) * $escala / 2;
        $cor = Cor::hex(self::NV);

        foreach ($letras as $i => $letra) {
            $m = $a + $larguras[$i] * $escala / 2;
            $tela->texto($letra, $cx + $r * cos($m), $cy + $r * sin($m), $fonte, $tamanho, $cor, Alinhamento::Centro, $m + M_PI / 2);
            $a += $larguras[$i] * $escala;
        }
    }

    /** @return list<array{float, float}> */
    private static function elipse(float $cx, float $cy, float $rx, float $ry, float $giro): array
    {
        $pontos = [];
        for ($i = 0; $i <= 144; ++$i) {
            $t = $i / 144 * M_PI * 2;
            $x = $rx * cos($t);
            $y = $ry * sin($t);
            $pontos[] = [$cx + $x * cos($giro) - $y * sin($giro), $cy + $x * sin($giro) + $y * cos($giro)];
        }

        return $pontos;
    }

    /** @return list<array{float, float}> */
    private static function circulo(float $cx, float $cy, float $r): array
    {
        return self::elipse($cx, $cy, $r, $r, 0.0);
    }

    /**
     * @param array{float, float} $de
     * @param array{float, float} $ate
     *
     * @return array{float, float}
     */
    private static function normal(array $de, array $ate): array
    {
        $dx = $ate[0] - $de[0];
        $dy = $ate[1] - $de[1];
        $tamanho = hypot($dx, $dy);

        return [-$dy / $tamanho, $dx / $tamanho];
    }
}
