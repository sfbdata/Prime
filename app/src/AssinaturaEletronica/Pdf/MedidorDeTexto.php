<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * A largura de um texto como o `measureText` do canvas: a soma dos avanços de cada letra, em unidades do desenho.
 *
 * O `imagettfbbox` custa perto de 90 µs por chamada e o encolhimento das duas colunas mede o texto inteiro em até
 * 33 tamanhos. Por isso cada letra é medida uma vez, num tamanho grande de referência, e a largura é proporcional
 * ao tamanho (a GD não aplica kerning nas fontes OpenType, então a soma é exata). O avanço sai do "sanduíche":
 * largura de "H{letra}H" menos a de "HH", o que inclui os espaços e as margens laterais da letra.
 */
final class MedidorDeTexto
{
    /** Tamanho de referência, em px (300 pt a 96 dpi). */
    private const REFERENCIA_PX = 400.0;

    /** @var array<string, array<string, float>> avanço em px na referência, por fonte e letra */
    private static array $avancos = [];

    public static function largura(string $texto, Fonte $fonte, float $tamanho): float
    {
        if ($texto === '') {
            return 0.0;
        }

        $soma = 0.0;
        foreach (mb_str_split($texto) as $letra) {
            $soma += self::$avancos[$fonte->value][$letra] ?? self::medir($fonte, $letra);
        }

        return $soma * $tamanho / self::REFERENCIA_PX;
    }

    private static function medir(Fonte $fonte, string $letra): float
    {
        $pontos = self::REFERENCIA_PX * 0.75;
        $par = imagettfbbox($pontos, 0, $fonte->caminho(), 'HH');
        $comLetra = imagettfbbox($pontos, 0, $fonte->caminho(), 'H' . $letra . 'H');
        $avanco = ($par === false || $comLetra === false)
            ? 0.0
            : (float) (($comLetra[2] - $comLetra[0]) - ($par[2] - $par[0]));

        return self::$avancos[$fonte->value][$letra] = max(0.0, $avanco);
    }
}
