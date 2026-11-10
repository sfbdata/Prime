<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * Interna do carimbo: as expressões regulares sobre o PDF, com a falha do PCRE (limite de retrocesso, de pilha do JIT)
 * virando exceção. Uma falha nunca pode passar por "não casou": a caixa ilegível vira tamanho carta, como no pdf.js,
 * mas a expressão que falhou recusa o carimbo. As expressões são possessivas, para não retroceder sem fim.
 */
final class CarimboVisivelExpressao
{
    private function __construct()
    {
    }

    /**
     * As partes do casamento, ou null se não casou.
     *
     * @param 0|\PREG_OFFSET_CAPTURE $opcoes
     *
     * @return array<int|string, mixed>|null
     */
    public static function casar(string $padrao, string $texto, int $opcoes = 0, int $deslocamento = 0): ?array
    {
        $resultado = preg_match($padrao, $texto, $partes, $opcoes, $deslocamento);
        if ($resultado === false) {
            throw self::falha();
        }

        return $resultado === 1 ? $partes : null;
    }

    /**
     * Todos os casamentos, um vetor de partes por casamento. Só para texto já limitado (o quanto casa cabe na memória).
     *
     * @return list<array<int|string, string>>
     */
    public static function todas(string $padrao, string $texto): array
    {
        if (preg_match_all($padrao, $texto, $partes, \PREG_SET_ORDER) === false) {
            throw self::falha();
        }

        return $partes;
    }

    /** @return list<string> */
    public static function dividir(string $padrao, string $texto, int $limite): array
    {
        $partes = preg_split($padrao, $texto, $limite);
        if ($partes === false) {
            throw self::falha();
        }

        return $partes;
    }

    private static function falha(): \RuntimeException
    {
        return new \RuntimeException('Expressão regular falhou: ' . preg_last_error_msg());
    }
}
