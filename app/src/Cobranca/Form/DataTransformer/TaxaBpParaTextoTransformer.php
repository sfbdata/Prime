<?php

declare(strict_types=1);

namespace App\Cobranca\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Converte uma taxa entre o modelo (int em BASIS POINTS, como vive nos Input DTOs e nas colunas de
 * config de encargos — 100 bp = 1%) e a view (percentual em pt-BR, como o usuário digita/lê:
 * "1,00" ⇄ 100, "2,5" ⇄ 250, "20" ⇄ 2000). Taxa é insumo de dinheiro: no domínio ela é inteira e a
 * saída é montada com aritmética inteira (sem float). Só a entrada do usuário passa por um único
 * round, exatamente como o CentavosParaReaisTransformer já faz no módulo. Vazio ⇄ null. Entrada
 * malformada → TransformationFailedException (o Form vira erro de campo, não 500).
 *
 * @implements DataTransformerInterface<int|null, string|null>
 */
final class TaxaBpParaTextoTransformer implements DataTransformerInterface
{
    /** Modelo (bp int) → view ("1,00", "20,00", "1.000,00"). Null/'' → '' (campo vazio). */
    public function transform(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_int($value)) {
            throw new TransformationFailedException('Esperado um valor inteiro em basis points.');
        }

        // Aritmética inteira: nenhuma divisão em float na saída (evita "20,00" virar 19,999...).
        $sinal = $value < 0 ? '-' : '';
        $absoluto = abs($value);
        $inteiro = intdiv($absoluto, 100);
        $centesimos = $absoluto % 100;

        return $sinal . number_format($inteiro, 0, ',', '.') . ',' . str_pad((string) $centesimos, 2, '0', STR_PAD_LEFT);
    }

    /**
     * View (percentual digitado) → modelo (bp int). '' → null. Aceita "1,00", "1.000,50", "2,5",
     * "20", "1.5" (conveniência US); rejeita texto não numérico.
     */
    public function reverseTransform(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new TransformationFailedException('Esperado um texto de percentual.');
        }

        $texto = trim($value);
        if ($texto === '') {
            return null;
        }

        // Mesmo desmembramento do dinheiro: vírgula tem prioridade (pt-BR) e os pontos viram milhar;
        // sem vírgula, um ÚNICO ponto com 1–2 casas é decimal; qualquer outro ponto é milhar.
        if (str_contains($texto, ',')) {
            $normalizado = str_replace(',', '.', str_replace('.', '', $texto));
        } elseif (preg_match('/^-?\d+\.\d{1,2}$/', $texto) === 1) {
            $normalizado = $texto;
        } else {
            $normalizado = str_replace('.', '', $texto);
        }

        if (!is_numeric($normalizado)) {
            throw new TransformationFailedException(sprintf('"%s" não é um percentual válido.', $value));
        }

        // round antes do cast evita o erro de ponto flutuante (ex.: 2.5 * 100 = 249.9999...).
        return (int) round((float) $normalizado * 100);
    }
}
