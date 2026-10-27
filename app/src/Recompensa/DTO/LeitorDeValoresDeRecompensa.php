<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Recompensa\Entity\LancamentoDeRecompensa;
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

    /**
     * Como {@see centavos()}, mas com sinal: "-10,00" é estorno. Obrigatório, diferente de zero e até R$ 100.000,00.
     *
     * @throws RecompensaRecusada
     */
    public static function centavosComSinal(mixed $valor): int
    {
        $texto = self::texto($valor);
        if ($texto === null || $texto === '-') {
            throw new RecompensaRecusada('Informe o valor do crédito (use negativo para estornar).');
        }
        $negativo = str_starts_with($texto, '-');
        $centavos = self::centavos($negativo ? substr($texto, 1) : $texto) ?? 0;
        if ($centavos === 0 || $centavos > LancamentoDeRecompensa::VALOR_MAXIMO) {
            throw new RecompensaRecusada('Informe o valor do crédito (use negativo para estornar).');
        }

        return $negativo ? -$centavos : $centavos;
    }

    /**
     * O valor de um pagamento (baixa): positivo, diferente de zero e até R$ 100.000,00. Negativo recusa.
     *
     * @throws RecompensaRecusada
     */
    public static function centavosDePagamento(mixed $valor): int
    {
        $texto = self::texto($valor);
        $centavos = $texto === null || str_starts_with($texto, '-') ? 0 : (self::centavos($texto) ?? 0);
        if ($centavos === 0 || $centavos > LancamentoDeRecompensa::VALOR_MAXIMO) {
            throw new RecompensaRecusada('Informe um valor maior que zero e até o saldo.');
        }

        return $centavos;
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
