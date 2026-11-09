<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDoComprovante;

/**
 * O símbolo do BlueJus como o desenho o traça (L561): dois arcos de cores diferentes em volta de uma pasta. Serve
 * à marca grande do fundo e ao símbolo do cabeçalho.
 */
final class SimboloBlueJus
{
    public function __construct(
        private readonly TelaDoComprovante $tela,
    ) {
    }

    public function desenhar(float $cx, float $cy, float $tamanho, int $corArco, int $corPasta, float $espessura): void
    {
        $this->tela->linhas($this->arco($cx, $cy, $tamanho / 2, -2.6, .55), $espessura, $corArco);
        $this->tela->linhas($this->arco($cx, $cy, $tamanho / 2, .85, 3.35), $espessura, $corPasta);
        $q = $tamanho * .46;
        [$x, $y] = [$cx - $q / 2, $cy - $q * .36];
        $this->tela->poligono([
            [$x, $y], [$x + $q * .42, $y], [$x + $q * .52, $y + $q * .13],
            [$x + $q, $y + $q * .13], [$x + $q, $y + $q * .76], [$x, $y + $q * .76],
        ], $corPasta);
    }

    /** @return list<array{float, float}> */
    private function arco(float $cx, float $cy, float $raio, float $de, float $ate): array
    {
        $pontos = [];
        for ($i = 0; $i <= 60; ++$i) {
            $angulo = $de + ($ate - $de) * $i / 60;
            $pontos[] = [$cx + $raio * cos($angulo), $cy + $raio * sin($angulo)];
        }

        return $pontos;
    }
}
