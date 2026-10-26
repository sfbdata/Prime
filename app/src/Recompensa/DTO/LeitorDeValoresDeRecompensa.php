<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Recompensa\Exception\RecompensaRecusada;

/**
 * Lê os números que o Master digita nas recompensas. Vazio vale nulo (nas condições individuais, "regra geral"); o que
 * não for número válido recusa, sem adivinhar. Dinheiro sai em centavos inteiros.
 */
final class LeitorDeValoresDeRecompensa
{
    private const RECUSA = 'Informe os valores em números válidos.';

    /** "10,00", "1.000,50", "10", "10.5" ou o número 10: reais em texto, centavos inteiros na saída. Negativo recusa. */
    public static function centavos(mixed $valor): ?int
    {
        $texto = self::texto($valor);
        if ($texto === null) {
            return null;
        }
        if (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $texto) === 1) {
            $texto = str_replace('.', '', $texto);
        }
        if (preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $texto, $m) !== 1) {
            throw new RecompensaRecusada(self::RECUSA);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** Inteiro não negativo, em número ou texto só com dígitos. */
    public static function inteiro(mixed $valor): ?int
    {
        $texto = self::texto($valor);
        if ($texto === null) {
            return null;
        }
        if (preg_match('/^\d{1,9}$/', $texto) !== 1) {
            throw new RecompensaRecusada(self::RECUSA);
        }

        return (int) $texto;
    }

    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }
        if (!is_string($valor)) {
            throw new RecompensaRecusada(self::RECUSA);
        }
        $texto = trim($valor);

        return $texto === '' ? null : $texto;
    }
}
