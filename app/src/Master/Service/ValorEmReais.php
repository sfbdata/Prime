<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * Lê o valor digitado pelo Master ("1.234,56", "19,9", "19.90", "R$ 50") e devolve centavos. Nulo quando não há um
 * valor maior que zero. Com vírgula vale o formato brasileiro (ponto é milhar); só com ponto, ele é decimal quando
 * tem 1 ou 2 dígitos depois (`19.90`) e milhar nos outros casos (`1.234`).
 */
final class ValorEmReais
{
    public static function paraCentavos(mixed $texto): ?int
    {
        if (is_int($texto) || is_float($texto)) {
            $texto = (string) $texto;
        }
        if (!is_string($texto)) {
            return null;
        }
        $limpo = (string) preg_replace('/[^\d,.]/', '', $texto);
        if ($limpo === '' || preg_match('/\d/', $limpo) !== 1) {
            return null;
        }
        if (str_contains($limpo, ',')) {
            $limpo = str_replace(',', '.', str_replace('.', '', $limpo));
        } elseif (preg_match('/^\d+\.\d{1,2}$/', $limpo) !== 1) {
            $limpo = str_replace('.', '', $limpo);
        }
        if (preg_match('/^\d+(\.\d+)?$/', $limpo) !== 1) {
            return null;
        }
        $centavos = (int) round((float) $limpo * 100);

        return $centavos > 0 ? $centavos : null;
    }
}
