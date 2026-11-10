<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDaFolha;

use App\Ponto\Service\ImagemDoComprovante\Degrade;
use App\Ponto\Service\ImagemDoComprovante\SementeDoDesenho;

/**
 * As camadas de segurança do fundo da folha em PDF (desenho 16 L636 a L650), as do comprovante com os números da
 * folha, na ordem do desenho: fundo iridescente na diagonal, linhas onduladas, duas rosáceas, dois arcos largos, marca
 * d'água diagonal com o código e o nome, moldura dupla, bordas onduladas em três cores, microtexto no alto e no pé e a
 * faixa holográfica vertical.
 *
 * Sem o segredo do HMAC não há código (decisão 16 da E16): a marca d'água e o microtexto saem sem ele, e o microtexto
 * sem a palavra "AUTENTICA" (a folha sem código não se valida).
 */
final class PadroesDaFolha
{
    private const W = ImagemDaFolha::LARGURA;
    private const H = ImagemDaFolha::ALTURA;
    private const AZUL_ESCURO = '#0b3550';
    private const IRIDESCENTE = ['#eef6fb', '#f0f9f2', '#fcf7ec', '#f5f0fb', '#eef6fb'];
    private const HOLOGRAFICO = ['#7fb4cf', '#d9eef7', '#8fcaa5', '#e8dca8', '#7fb4cf'];
    private const LARGURA_DO_MICROTEXTO = 890;

    public function __construct(
        private readonly PaginaDaFolha $pagina,
        private readonly SementeDoDesenho $sorteio,
    ) {
    }

    public function desenhar(?string $codigo, string $nome): void
    {
        $tela = $this->pagina->tela();
        $this->fundoIridescente();
        $this->ondas();
        $this->rosacea(500, 640, 330, 9 + (int) floor($this->sorteio->proximo() * 5), 34, $tela->cor(self::AZUL_ESCURO, .06));
        $this->rosacea(780, 1250, 120, 6 + (int) floor($this->sorteio->proximo() * 4), 22, $tela->corRgb(30, 123, 69, .13));
        $this->arco(-2.6, .55, $tela->cor(self::AZUL_ESCURO, .05));
        $this->arco(.85, 3.35, $tela->cor('#0a6fa0', .05));
        $this->marcaDagua($codigo, $nome);
        $tela->contorno(12, 12, self::W - 24, self::H - 24, 2, $tela->cor(self::AZUL_ESCURO));
        $tela->contorno(34, 34, self::W - 68, self::H - 68, .8, $tela->cor(self::AZUL_ESCURO));
        $this->bordasOnduladas();
        $this->microtexto($codigo);
        $this->faixaVertical();
    }

    /** "BLUEJUS · CÓDIGO · NOME · " cinco vezes (L645); sem código, "BLUEJUS · NOME · ". */
    public static function linhaDaMarcaDagua(?string $codigo, string $nome): string
    {
        $codigo = $codigo === null ? '' : $codigo . ' · ';

        return str_repeat('BLUEJUS · ' . $codigo . mb_strtoupper($nome) . ' · ', 5);
    }

    /**
     * "BLUEJUS PONTO ELETRONICO FOLHA AUTENTICA CÓDIGO " repetido (L649), antes do corte na largura; sem código,
     * "BLUEJUS PONTO ELETRONICO FOLHA " repetido o bastante para encher a mesma largura.
     */
    public static function textoDoMicrotexto(?string $codigo): string
    {
        return $codigo === null
            ? str_repeat('BLUEJUS PONTO ELETRONICO FOLHA ', 18)
            : str_repeat('BLUEJUS PONTO ELETRONICO FOLHA AUTENTICA ' . $codigo . ' ', 9);
    }

    /**
     * O fundo iridescente do canto de cima à esquerda ao de baixo à direita (L638), como o `createLinearGradient(0, 0,
     * W, H)` do desenho: a cor de cada ponto é a da projeção dele na diagonal. Pintado em faixas perpendiculares à
     * diagonal, uma por cor distinta (as cores claras do desenho dão poucas dezenas), e não ampliando uma imagem
     * pequena como o comprovante: a ampliação para 2480 x 3507 levava quase 2 s, as faixas levam centésimos.
     */
    private function fundoIridescente(): void
    {
        $norma = self::W ** 2 + self::H ** 2;
        $sobra = 2 * sqrt($norma);   // cada faixa avança 2 sobre a seguinte, que pinta por cima: sem fresta entre elas
        foreach (self::faixasDeCor(self::IRIDESCENTE) as [$de, $ate, $cor]) {
            [$s0, $s1] = [$de * $norma, $ate * $norma + $sobra];
            $this->pagina->tela()->poligono([[$s0 / self::W, 0.0], [$s1 / self::W, 0.0], [0.0, $s1 / self::H], [0.0, $s0 / self::H]], $this->pagina->tela()->corRgb(...$cor));
        }
    }

    /**
     * Os trechos do degradê com a mesma cor, de 0 a 1.
     *
     * @param list<string> $paradas
     *
     * @return list<array{float, float, array{int, int, int}}> início, fim e a cor de cada trecho
     */
    private static function faixasDeCor(array $paradas): array
    {
        $passos = 4096;
        $faixas = [[0.0, 0.0, Degrade::cor($paradas, 0.0)]];
        for ($i = 1; $i <= $passos; ++$i) {
            $cor = Degrade::cor($paradas, $i / $passos);
            $ultima = count($faixas) - 1;
            $faixas[$ultima][1] = $i / $passos;
            if ($cor !== $faixas[$ultima][2]) {
                $faixas[] = [$i / $passos, $i / $passos, $cor];
            }
        }

        return $faixas;
    }

    /** 66 linhas onduladas, alternando azul e verde, com fase e frequências sorteadas (L639 e L640). */
    private function ondas(): void
    {
        $fase = $this->sorteio->proximo() * 6;
        $f1 = .012 + $this->sorteio->proximo() * .012;
        $f2 = .03 + $this->sorteio->proximo() * .02;
        $azul = $this->pagina->tela()->corRgb(10, 111, 160, .13);
        $verde = $this->pagina->tela()->corRgb(30, 123, 69, .1);
        for ($k = -2; $k < 64; ++$k) {
            $y0 = $k * self::H / 60;
            $pontos = [];
            for ($x = 0; $x <= self::W; $x += 5) {
                $pontos[] = [(float) $x, $y0 + 13 * sin($x * $f1 + $fase + $k * .32) + 5 * sin($x * $f2 + $k * .7)];
            }
            $this->pagina->tela()->linhas($pontos, .5, $k % 2 !== 0 ? $azul : $verde);
        }
    }

    /** Rosácea de `curvas` curvas com `petalas` pétalas e fase sorteada, em fio 0,45 (L641). */
    private function rosacea(float $cx, float $cy, float $raio, int $petalas, int $curvas, int $cor): void
    {
        $fase = $this->sorteio->proximo() * 3;
        for ($j = 0; $j < $curvas; ++$j) {
            $a0 = $j / $curvas * M_PI * 2;
            $pontos = [];
            for ($i = 0; $i <= 200; ++$i) {
                $t = $i / 200 * M_PI * 2;
                $r = $raio * (.66 + .34 * sin($petalas * $t + $fase));
                $pontos[] = [$cx + $r * cos($t + $a0), $cy + $r * sin($t + $a0)];
            }
            $this->pagina->tela()->linhas($pontos, .45, $cor);
        }
    }

    /**
     * Arco largo (fio 30) de raio 230 em volta de (500, 640), entre dois ângulos (L643): pintado como uma faixa só, para
     * a cor translúcida não escurecer onde os pedaços se encostam.
     */
    private function arco(float $de, float $ate, int $cor): void
    {
        [$fora, $dentro] = [[], []];
        for ($i = 0; $i <= 120; ++$i) {
            $a = $de + ($ate - $de) * $i / 120;
            $fora[] = [500 + 245 * cos($a), 640 + 245 * sin($a)];
            $dentro[] = [500 + 215 * cos($a), 640 + 215 * sin($a)];
        }
        $this->pagina->tela()->poligono([...$fora, ...array_reverse($dentro)], $cor);
    }

    /** A marca d'água em linhas diagonais por toda a folha, negrito 18 a 5% de opacidade (L644). */
    private function marcaDagua(?string $codigo, string $nome): void
    {
        $angulo = -M_PI / 6.4;
        $linha = self::linhaDaMarcaDagua($codigo, $nome);
        for ($y = -1050, $i = 0; $y < 1050; $y += 62, ++$i) {
            $x = -1400 + ($i % 2) * 80;
            $this->pagina->texto($linha, 'negrito', 18, self::W / 2 + $x * cos($angulo) - $y * sin($angulo), self::H / 2 + $x * sin($angulo) + $y * cos($angulo), self::AZUL_ESCURO, false, .05, rad2deg(-$angulo));
        }
    }

    /** Três senoides por lado, a 23 da borda, em azul, verde e ocre (L646 a L648). */
    private function bordasOnduladas(): void
    {
        $lados = [[23, 23, self::W - 23, 23], [self::W - 23, 23, self::W - 23, self::H - 23], [self::W - 23, self::H - 23, 23, self::H - 23], [23, self::H - 23, 23, 23]];
        foreach ($lados as [$x0, $y0, $x1, $y1]) {
            $comprimento = hypot($x1 - $x0, $y1 - $y0);
            [$ux, $uy] = [($x1 - $x0) / $comprimento, ($y1 - $y0) / $comprimento];
            foreach ([[0, '#0a6fa0'], [2.1, '#1e7b45'], [4.2, '#8a6d1f']] as [$fase, $hex]) {
                $pontos = [];
                for ($s = 0; $s <= $comprimento; $s += 2) {
                    $o = 8 * sin($s * .1 + $fase);
                    $pontos[] = [$x0 + $ux * $s - $uy * $o, $y0 + $uy * $s + $ux * $o];
                }
                $this->pagina->tela()->linhas($pontos, .7, $this->pagina->tela()->cor($hex));
            }
        }
    }

    /** Microtexto de 5 no alto e no pé, cortado em 890 de largura (L649). */
    private function microtexto(?string $codigo): void
    {
        $texto = self::cortarNaLargura($this->pagina, self::textoDoMicrotexto($codigo), 5, self::LARGURA_DO_MICROTEXTO);
        $this->pagina->texto($texto, 'normal', 5, 46, 42, self::AZUL_ESCURO);
        $this->pagina->texto($texto, 'normal', 5, 46, self::H - 39, self::AZUL_ESCURO);
    }

    /** Blocos holográficos de 12 x 62 com "BLUEJUS PONTO" de baixo para cima, em x = 945 (L650). */
    private function faixaVertical(): void
    {
        for ($y = 0; $y + 74 < self::H - 96; $y += 100) {
            $this->pagina->tela()->degradeEntrePontos([945, 48 + $y, 957, 48 + $y + 62], [945, 48 + $y + 8, 12, 62], self::HOLOGRAFICO);
            $this->pagina->texto('BLUEJUS PONTO', 'negrito', 6.5, 945 + 8.5, 48 + $y + 66, self::AZUL_ESCURO, false, 1.0, 90.0);
        }
    }

    /** O maior começo do texto que cabe na largura, sem reticências (o `while ... slice(0, -1)` do L649). */
    private static function cortarNaLargura(PaginaDaFolha $pagina, string $texto, float $tamanho, float $limite): string
    {
        [$menor, $maior] = [0, mb_strlen($texto)];
        while ($menor < $maior) {
            $meio = intdiv($menor + $maior + 1, 2);
            if ($pagina->largura(mb_substr($texto, 0, $meio), 'normal', $tamanho) <= $limite) {
                $menor = $meio;
                continue;
            }
            $maior = $meio - 1;
        }

        return mb_substr($texto, 0, $menor);
    }
}
