<?php

declare(strict_types=1);

namespace App\Cobranca\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Converte o percentual de honorários entre o modelo (string em formato "ponto", ex.: "10.50", como
 * exige a coluna decimal(5,2) e os #[Assert] do DTO) e a view (o usuário digita/lê em pt-BR com
 * vírgula, ex.: "10,50"). Vazio ⇄ null. Entrada malformada → TransformationFailedException (o Form
 * vira erro de campo, não 500). Não é dinheiro (sem centavos) — só normaliza o separador decimal.
 *
 * @implements DataTransformerInterface<string|null, string|null>
 */
final class PercentualParaTextoTransformer implements DataTransformerInterface
{
    /** Modelo ("10.50") → view ("10,50"). Null/'' → '' (campo vazio). */
    public function transform(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_string($value)) {
            throw new TransformationFailedException('Esperado um percentual em texto.');
        }

        return str_replace('.', ',', trim($value));
    }

    /** View ("10,50" ou "10.50") → modelo ("10.50"). '' → null. */
    public function reverseTransform(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new TransformationFailedException('Esperado um percentual em texto.');
        }

        $texto = trim($value);
        if ($texto === '') {
            return null;
        }

        // pt-BR usa vírgula como decimal; normaliza para ponto (formato do decimal(5,2) / DTO).
        return str_replace(',', '.', $texto);
    }
}
