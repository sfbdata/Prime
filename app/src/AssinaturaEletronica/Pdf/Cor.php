<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * Cor do canvas do desenho: `#rrggbb` ou `rgba(r,g,b,a)` com alfa de 0 (transparente) a 1 (opaca).
 */
final readonly class Cor
{
    public function __construct(
        public int $r,
        public int $g,
        public int $b,
        public float $alfa = 1.0,
    ) {
    }

    public static function hex(string $hex, float $alfa = 1.0): self
    {
        $valor = (int) hexdec(ltrim($hex, '#'));

        return new self(($valor >> 16) & 0xFF, ($valor >> 8) & 0xFF, $valor & 0xFF, $alfa);
    }

    public function comAlfa(float $alfa): self
    {
        return new self($this->r, $this->g, $this->b, max(0.0, min(1.0, $alfa)));
    }

    /** O alfa da GD vai de 0 (opaca) a 127 (transparente). */
    public function alfaDaGd(): int
    {
        return (int) round((1.0 - $this->alfa) * 127);
    }

    /** Mistura linear com outra cor (gradiente do canvas, em sRGB). */
    public function misturar(self $outra, float $t): self
    {
        return new self(
            (int) round($this->r + ($outra->r - $this->r) * $t),
            (int) round($this->g + ($outra->g - $this->g) * $t),
            (int) round($this->b + ($outra->b - $this->b) * $t),
            $this->alfa,
        );
    }

    /**
     * Cor de um gradiente de paradas iguais (addColorStop(i / (n - 1))) no ponto $t de 0 a 1.
     *
     * @param list<self> $paradas
     */
    public static function noGradiente(array $paradas, float $t): self
    {
        $t = max(0.0, min(1.0, $t)) * (\count($paradas) - 1);
        $i = min((int) floor($t), \count($paradas) - 2);

        return $paradas[$i]->misturar($paradas[$i + 1], $t - $i);
    }
}
