<?php

declare(strict_types=1);

namespace App\Ponto\Service\ImagemDoComprovante;

/** A cor de um degradê de paradas igualmente espaçadas no ponto `t` (0 a 1), como o `addColorStop(i / n)` do canvas. */
final class Degrade
{
    /**
     * @param list<string> $paradas cores `#rrggbb`
     *
     * @return array{int, int, int}
     */
    public static function cor(array $paradas, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        $trechos = count($paradas) - 1;
        if ($trechos < 1) {
            return TelaDoComprovante::rgb($paradas[0]);
        }
        $indice = min($trechos - 1, (int) floor($t * $trechos));
        $local = $t * $trechos - $indice;
        $de = TelaDoComprovante::rgb($paradas[$indice]);
        $para = TelaDoComprovante::rgb($paradas[$indice + 1]);

        return [
            (int) round($de[0] + ($para[0] - $de[0]) * $local),
            (int) round($de[1] + ($para[1] - $de[1]) * $local),
            (int) round($de[2] + ($para[2] - $de[2]) * $local),
        ];
    }
}
