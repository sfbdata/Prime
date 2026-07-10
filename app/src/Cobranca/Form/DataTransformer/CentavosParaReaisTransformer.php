<?php

declare(strict_types=1);

namespace App\Cobranca\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Converte dinheiro entre o modelo (int em CENTAVOS, como vive nos Input DTOs) e a view (string em
 * reais pt-BR, como o usuário digita/lê). Aritmética inteira — nenhum valor de dinheiro trafega como
 * float no domínio. Vazio ⇄ null. Entrada malformada → TransformationFailedException (o Form vira
 * erro de campo, não 500).
 *
 * @implements DataTransformerInterface<int|null, string|null>
 */
final class CentavosParaReaisTransformer implements DataTransformerInterface
{
    /**
     * Modelo (centavos int) → view (reais "1.234,56"). Null → '' (campo vazio).
     */
    public function transform(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_int($value)) {
            throw new TransformationFailedException('Esperado um valor inteiro em centavos.');
        }

        return number_format($value / 100, 2, ',', '.');
    }

    /**
     * View (reais digitados) → modelo (centavos int). '' → null. Aceita "1.234,56", "1234,56",
     * "1234.56" e "1234"; rejeita texto não numérico.
     */
    public function reverseTransform(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new TransformationFailedException('Esperado um texto de valor monetário.');
        }

        $texto = trim($value);
        if ($texto === '') {
            return null;
        }

        // Descobre o separador decimal. Vírgula tem prioridade (pt-BR): pontos viram milhar. Sem
        // vírgula, um ÚNICO ponto final com 1–2 casas é decimal (conveniência US "1234.56"); qualquer
        // outro ponto é separador de milhar (ex.: "1.234", "1.234.567").
        if (str_contains($texto, ',')) {
            $normalizado = str_replace(',', '.', str_replace('.', '', $texto));
        } elseif (preg_match('/^-?\d+\.\d{1,2}$/', $texto) === 1) {
            $normalizado = $texto;
        } else {
            $normalizado = str_replace('.', '', $texto);
        }

        if (!is_numeric($normalizado)) {
            throw new TransformationFailedException(sprintf('"%s" não é um valor monetário válido.', $value));
        }

        // round antes do cast evita o erro de ponto flutuante (ex.: 12.35 * 100 = 1234.9999...).
        return (int) round((float) $normalizado * 100);
    }
}
