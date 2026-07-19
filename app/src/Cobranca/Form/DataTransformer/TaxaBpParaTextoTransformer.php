<?php

declare(strict_types=1);

namespace App\Cobranca\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Converte uma taxa entre o modelo (int em BASIS POINTS, como vive nos Input DTOs e nas colunas de
 * config de encargos — 100 bp = 1%) e a view (percentual em pt-BR, como o usuário digita/lê:
 * "1,00" ⇄ 100, "2,5" ⇄ 250, "20" ⇄ 2000). Taxa é insumo de dinheiro: os DOIS sentidos são montados
 * com aritmética inteira, sem nenhum float no caminho — inclusive o arredondamento da 3ª casa decimal
 * na entrada (ver `paraBasisPoints`, que explica por que o `round()` sobre float não servia).
 * Vazio ⇄ null. Entrada malformada → TransformationFailedException (o Form vira erro de campo, não 500).
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

        return $this->paraBasisPoints($normalizado, $value);
    }

    /**
     * Converte o decimal já normalizado ("1.005", "1000", "-2.5") para basis points SEM passar por
     * float, arredondando a 3ª casa em diante por HALF-UP (afastando-se do zero) — a mesma regra que a
     * CalculadoraEncargos usa no resto do caminho do dinheiro.
     *
     * POR QUE NÃO `(int) round((float) $x * 100)`, que era o código anterior: o produto em ponto
     * flutuante não é exato. Para "1,005" o double resultante vale, exatamente,
     * 100.4999999999999857891452847979962825775146484375 — ou seja, ABAIXO de 100,5. O resultado certo
     * (101) só aparecia porque o `round()` do PHP aplica um pré-arredondamento interno, heurístico e
     * não documentado como contrato, que mascara o erro; é exatamente esse comportamento de borda que
     * o PHP 8.4 reescreveu. Depender dele significa que a taxa da carteira poderia mudar de valor ao
     * atualizar o PHP, sem nenhum aviso. Aritmética inteira remove a dúvida: o resultado passa a ser
     * função só do texto digitado, igual em qualquer versão.
     *
     * Optou-se por CONVERTER com precisão (e não por rejeitar 3+ casas) para preservar o
     * comportamento já documentado e testado do módulo — "1,005" continua virando 101 bp —, evitando
     * transformar em erro de campo uma entrada que hoje é aceita.
     */
    private function paraBasisPoints(string $normalizado, string $original): int
    {
        // Notação científica ("1e3") passa no is_numeric mas não é entrada legítima de percentual.
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $normalizado, $partes) !== 1) {
            throw new TransformationFailedException(sprintf('"%s" não é um percentual válido.', $original));
        }

        $inteiro = $partes[2];
        $decimais = $partes[3] ?? '';

        if ($inteiro === '' && $decimais === '') {
            throw new TransformationFailedException(sprintf('"%s" não é um percentual válido.', $original));
        }

        // Guarda de overflow: sem ela um número absurdamente longo estouraria o int em silêncio (o
        // teto de sanidade do DTO só age depois, sobre um valor que já teria sido corrompido aqui).
        if (strlen($inteiro) > 15) {
            throw new TransformationFailedException(sprintf('"%s" é um percentual grande demais.', $original));
        }

        // 1% = 100 bp: as duas primeiras casas decimais são os próprios basis points.
        $centesimos = substr(str_pad($decimais, 2, '0'), 0, 2);
        $bp = ((int) $inteiro) * 100 + (int) $centesimos;

        // HALF-UP: a 3ª casa decimal >= 5 significa resto >= 0,5 bp, então sobe. As casas seguintes
        // não mudam a decisão (se a 3ª já é >= 5, o resto já passou de meio; se é < 5, não alcança).
        if (isset($decimais[2]) && $decimais[2] >= '5') {
            ++$bp;
        }

        return $partes[1] === '-' ? -$bp : $bp;
    }
}
