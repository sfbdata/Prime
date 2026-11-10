<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

use App\AssinaturaEletronica\Pdf\Exception\FalhaAoMontarPdf;
use App\Shared\Service\OrcamentoDeMemoriaDeImagem;

/**
 * Uma página do PDF final: imagem GD de 2480 x 3507 px desenhada nas coordenadas do desenho (W = 1000, H = 1414,
 * escala 2,48; `pdf()` em dc:392-427). É o "canvas" do bloco: retângulo, contorno, linha, polígono, texto (com giro
 * e alinhamento) e imagem, sempre em unidades do desenho.
 *
 * API para quem desenha por cima da moldura (o manifesto do bloco 1b):
 *  - {@see retangulo()} = `fillRect`; {@see contorno()} = `strokeRect`; {@see linha()} = `stroke` de um caminho;
 *  - {@see texto()} = `fillText` (y é a linha de base, como `textBaseline = 'alphabetic'`);
 *    {@see largura()} = `measureText().width`;
 *  - {@see imagem()} = `drawImage` (a rubrica já decodificada vem de {@see RecursosDaFolha::rubrica()}).
 *
 * Linha translúcida mais fina que 1,75 px vira fio de 1 px com o alfa multiplicado pela espessura: a mesma tinta por
 * comprimento que o canvas espalha com antisserrilhado, sem as emendas escurecidas dos segmentos.
 * {@see ampliar()} devolve um recorte 4 vezes maior, onde o selo e a borda ondulada ganham o antisserrilhado que a GD
 * não tem em linha grossa e polígono.
 */
final class Tela
{
    public const W = 1000.0;
    public const H = 1414.0;
    public const ESCALA = 2.48;
    public const LARGURA_PX = 2480;
    public const ALTURA_PX = 3507;

    /** O `imagettftext` mede em pontos a 96 dpi: 1 px = 0,75 pt. */
    private const PT_POR_PX = 0.75;
    private const FIO_FINO_PX = 1.75;
    /** Cada faixa do gradiente avança um pouco sobre a seguinte (em unidades), para não sobrar fresta. */
    private const SOBRA_DA_FAIXA = .5;

    /** @var array<string, int> */
    private array $cores = [];
    private bool $liberada = false;

    /** @param array{int, int, int, int}|null $regiao do recorte na página de origem: x, y, largura e altura em px */
    private function __construct(
        private readonly \GdImage $imagem,
        private readonly float $escala,
        private readonly float $dx,
        private readonly float $dy,
        private readonly ?Tela $origem = null,
        private readonly ?array $regiao = null,
    ) {
        imagealphablending($imagem, true);
    }

    /**
     * Página nova, branca (o canvas transparente viraria preto no JPEG). Sem `$branca`, a página nasce preta para
     * quem vai cobri-la inteira logo em seguida (o gradiente do fundo): pintar 8,7 milhões de pixels duas vezes custa.
     */
    public static function pagina(bool $branca = true): self
    {
        $imagem = self::criarImagem(self::LARGURA_PX, self::ALTURA_PX);
        if ($branca) {
            imagealphablending($imagem, false);
            imagefilledrectangle($imagem, 0, 0, self::LARGURA_PX - 1, self::ALTURA_PX - 1, 0xFFFFFF);
        }

        return new self($imagem, self::ESCALA, 0.0, 0.0);
    }

    /**
     * O retângulo (x, y, largura, altura) ampliado $fator vezes, desenhado nas mesmas coordenadas da página. Depois de
     * desenhar, {@see recolher()} reduz o recorte de volta para a página.
     */
    public function ampliar(float $x, float $y, float $largura, float $altura, int $fator): self
    {
        $x0 = max(0, (int) floor($this->x($x)));
        $y0 = max(0, (int) floor($this->y($y)));
        $larguraPx = min(imagesx($this->imagem), (int) ceil($this->x($x + $largura))) - $x0;
        $alturaPx = min(imagesy($this->imagem), (int) ceil($this->y($y + $altura))) - $y0;

        $ampliada = self::criarImagem($larguraPx * $fator, $alturaPx * $fator);
        imagecopyresized($ampliada, $this->imagem, 0, 0, $x0, $y0, $larguraPx * $fator, $alturaPx * $fator, $larguraPx, $alturaPx);

        return new self(
            $ampliada,
            $this->escala * $fator,
            ($this->dx - $x0) * $fator,
            ($this->dy - $y0) * $fator,
            $this,
            [$x0, $y0, $larguraPx, $alturaPx],
        );
    }

    /** Reduz o recorte de {@see ampliar()} de volta para a página (média por área: o antisserrilhado). */
    public function recolher(): void
    {
        if ($this->origem === null || $this->regiao === null) {
            return;
        }

        [$x0, $y0, $largura, $altura] = $this->regiao;
        imagecopyresampled($this->origem->imagem, $this->imagem, $x0, $y0, 0, 0, $largura, $altura, imagesx($this->imagem), imagesy($this->imagem));
        $this->liberar();
    }

    /** `fillRect`, com as bordas arredondadas para o pixel mais próximo (no mínimo 1 px). */
    public function retangulo(float $x, float $y, float $largura, float $altura, Cor $cor): void
    {
        $x0 = (int) round($this->x($x));
        $y0 = (int) round($this->y($y));
        $x1 = max($x0, (int) round($this->x($x + $largura)) - 1);
        $y1 = max($y0, (int) round($this->y($y + $altura)) - 1);

        imagefilledrectangle($this->imagem, $x0, $y0, $x1, $y1, $this->cor($cor));
    }

    /** `strokeRect`: quatro faixas centradas no contorno, sem sobrepor os cantos (o alfa não dobra). */
    public function contorno(float $x, float $y, float $largura, float $altura, float $espessura, Cor $cor): void
    {
        $m = $espessura / 2;
        $this->retangulo($x - $m, $y - $m, $largura + $espessura, $espessura, $cor);
        $this->retangulo($x - $m, $y + $altura - $m, $largura + $espessura, $espessura, $cor);
        $this->retangulo($x - $m, $y + $m, $espessura, $altura - $espessura, $cor);
        $this->retangulo($x + $largura - $m, $y + $m, $espessura, $altura - $espessura, $cor);
    }

    /**
     * `stroke` de um caminho de pontos [x, y].
     *
     * @param list<array{float, float}> $pontos
     */
    public function linha(array $pontos, float $espessura, Cor $cor, bool $fechar = false): void
    {
        if ($fechar && $pontos !== []) {
            $pontos[] = $pontos[0];
        }

        $px = $espessura * $this->escala;
        $fino = $cor->alfa < 1.0 && $px < self::FIO_FINO_PX;
        $tinta = $this->cor($fino ? $cor->comAlfa($cor->alfa * max(1.0, $px)) : $cor);
        imagesetthickness($this->imagem, $fino ? 1 : max(1, (int) round($px)));

        $anterior = null;
        $ultimo = \count($pontos) - 1;
        foreach ($pontos as $i => [$x, $y]) {
            $atual = [(int) floor($this->x($x)), (int) floor($this->y($y))];
            if ($anterior !== null) {
                $this->segmento($anterior, $atual, $tinta, $fino && ($fechar || $i < $ultimo));
            }
            $anterior = $atual;
        }

        imagesetthickness($this->imagem, 1);
    }

    /**
     * `fill` de um polígono (a GD preenche por linhas de varredura, par e ímpar: polígono com furo é contorno de fora
     * mais o de dentro no sentido contrário).
     *
     * @param list<array{float, float}> $pontos
     */
    public function poligono(array $pontos, Cor $cor): void
    {
        if (\count($pontos) < 3) {
            return;
        }

        $planos = [];
        foreach ($pontos as [$x, $y]) {
            $planos[] = (int) round($this->x($x));
            $planos[] = (int) round($this->y($y));
        }

        imagefilledpolygon($this->imagem, $planos, $this->cor($cor));
    }

    /**
     * `fillText`. O giro é o do `rotate()` do canvas, em radianos (positivo gira no sentido horário da tela);
     * o alinhamento corre ao longo da linha de base girada.
     */
    public function texto(string $texto, float $x, float $y, Fonte $fonte, float $tamanho, Cor $cor, Alinhamento $alinhamento = Alinhamento::Esquerda, float $giro = 0.0): void
    {
        if (trim($texto) === '') {
            return;
        }

        $recuo = match ($alinhamento) {
            Alinhamento::Esquerda => 0.0,
            Alinhamento::Centro => -$this->largura($texto, $fonte, $tamanho) / 2,
            Alinhamento::Direita => -$this->largura($texto, $fonte, $tamanho),
        };
        $x += cos($giro) * $recuo;
        $y += sin($giro) * $recuo;

        $pontos = $tamanho * $this->escala * self::PT_POR_PX;
        imagettftext($this->imagem, $pontos, -rad2deg($giro), (int) round($this->x($x)), (int) round($this->y($y)), $this->cor($cor), $fonte->caminho(), $texto);
    }

    /** `measureText(texto).width`, em unidades do desenho. */
    public function largura(string $texto, Fonte $fonte, float $tamanho): float
    {
        return MedidorDeTexto::largura($texto, $fonte, $tamanho);
    }

    /** `drawImage(imagem, x, y, largura, altura)`, respeitando a transparência da imagem. */
    public function imagem(\GdImage $imagem, float $x, float $y, float $largura, float $altura): void
    {
        imagecopyresampled(
            $this->imagem,
            $imagem,
            (int) round($this->x($x)),
            (int) round($this->y($y)),
            0,
            0,
            max(1, (int) round($largura * $this->escala)),
            max(1, (int) round($altura * $this->escala)),
            imagesx($imagem),
            imagesy($imagem),
        );
    }

    /**
     * `createLinearGradient(de, ate)` com paradas iguais, pintado no retângulo: faixas perpendiculares ao gradiente,
     * de `$passo` unidades, cada uma na cor do seu meio, recortadas no retângulo (vizinhas diferem menos de um tom).
     *
     * @param array{float, float} $de
     * @param array{float, float} $ate
     * @param list<Cor>           $paradas
     */
    public function gradiente(float $x, float $y, float $largura, float $altura, array $de, array $ate, array $paradas, float $passo): void
    {
        $vx = $ate[0] - $de[0];
        $vy = $ate[1] - $de[1];
        $comprimento = hypot($vx, $vy);
        [$ux, $uy] = [$vx / $comprimento, $vy / $comprimento];
        $cantos = [[$x, $y], [$x + $largura, $y], [$x, $y + $altura], [$x + $largura, $y + $altura]];
        $projecoes = array_map(static fn (array $c): float => ($c[0] - $de[0]) * $ux + ($c[1] - $de[1]) * $uy, $cantos);
        $alcance = $largura + $altura;

        // As paradas são opacas: sem mistura de alfa, cada pixel é só gravado.
        imagealphablending($this->imagem, false);
        imagesetclip($this->imagem, (int) round($this->x($x)), (int) round($this->y($y)), (int) round($this->x($x + $largura)) - 1, (int) round($this->y($y + $altura)) - 1);
        for ($s = min($projecoes); $s < max($projecoes); $s += $passo) {
            $cor = Cor::noGradiente($paradas, ($s + $passo / 2) / $comprimento);
            $ax = $de[0] + $ux * $s;
            $ay = $de[1] + $uy * $s;
            $bx = $ax + $ux * ($passo + self::SOBRA_DA_FAIXA);
            $by = $ay + $uy * ($passo + self::SOBRA_DA_FAIXA);
            $this->poligono([
                [$ax - $uy * $alcance, $ay + $ux * $alcance],
                [$ax + $uy * $alcance, $ay - $ux * $alcance],
                [$bx + $uy * $alcance, $by - $ux * $alcance],
                [$bx - $uy * $alcance, $by + $ux * $alcance],
            ], $cor);
        }
        imagesetclip($this->imagem, 0, 0, imagesx($this->imagem) - 1, imagesy($this->imagem) - 1);
        imagealphablending($this->imagem, true);
    }

    /** A imagem GD da página (para o escritor do PDF). */
    public function gd(): \GdImage
    {
        return $this->imagem;
    }

    public function liberar(): void
    {
        if ($this->liberada) {
            return;
        }

        $this->liberada = true;
        imagedestroy($this->imagem);
    }

    private static function criarImagem(int $largura, int $altura): \GdImage
    {
        if (!OrcamentoDeMemoriaDeImagem::cabe($largura, $altura)) {
            throw new FalhaAoMontarPdf(sprintf('Sem memória para a imagem de %d x %d px do PDF.', $largura, $altura));
        }

        $imagem = imagecreatetruecolor($largura, $altura);
        if ($imagem === false) {
            throw new FalhaAoMontarPdf(sprintf('A GD não criou a imagem de %d x %d px do PDF.', $largura, $altura));
        }

        return $imagem;
    }

    /**
     * Um segmento do caminho. No fio fino o último pixel fica para o segmento seguinte, que começa nele: assim a
     * emenda não recebe o alfa duas vezes.
     *
     * @param array{int, int} $de
     * @param array{int, int} $ate
     */
    private function segmento(array $de, array $ate, int $tinta, bool $encurtar): void
    {
        [$x0, $y0] = $de;
        [$x1, $y1] = $ate;
        if ($x0 === $x1 && $y0 === $y1) {
            return;
        }

        if ($encurtar) {
            $dx = $x1 - $x0;
            $dy = $y1 - $y0;
            if (abs($dx) >= abs($dy)) {
                $x1 -= $dx <=> 0;
                $y1 = $y0 + (int) round(($x1 - $x0) * $dy / $dx);
            } else {
                $y1 -= $dy <=> 0;
                $x1 = $x0 + (int) round(($y1 - $y0) * $dx / $dy);
            }
        }

        imageline($this->imagem, $x0, $y0, $x1, $y1, $tinta);
    }

    private function cor(Cor $cor): int
    {
        $chave = $cor->r . ',' . $cor->g . ',' . $cor->b . ',' . $cor->alfaDaGd();

        return $this->cores[$chave] ??= (int) imagecolorallocatealpha($this->imagem, $cor->r, $cor->g, $cor->b, $cor->alfaDaGd());
    }

    private function x(float $x): float
    {
        return $x * $this->escala + $this->dx;
    }

    private function y(float $y): float
    {
        return $y * $this->escala + $this->dy;
    }
}
