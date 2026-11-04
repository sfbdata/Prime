<?php

declare(strict_types=1);

namespace App\Sync\Service;

/**
 * Normalização pura de nomes de pasta para o reconhecimento do Drive (spec `docs/specs/drive-reconhecimento.md`).
 *
 * Dois nomes "iguais para uma pessoa" viram a mesma chave: sem diferença de caixa, sem acento, sem espaço nas
 * bordas e sem espaço repetido. O traço longo e o meio traço digitados no Drive contam como hífen.
 */
final class NormalizadorDeNomeDoDrive
{
    /** Número na frente do nome ("001 - ", "12A. "), que o "Importar em lote" e o acervo antigo usam. */
    private const NUMERO_NA_FRENTE = '/^\d+[a-z]?(\s*[-.]\s*|\s+)/u';

    /** Chave de comparação de um nome inteiro ("NUP - CLIENTE - AÇÃO" contra o nome da pasta do Drive). */
    public static function normalizar(string $nome): string
    {
        $semAcento = self::semAcento($nome);
        $hifen = (string) preg_replace('/[\x{2010}-\x{2015}]/u', '-', $semAcento);
        $espacos = (string) preg_replace('/\s+/u', ' ', $hifen);

        return mb_strtolower(trim($espacos));
    }

    /**
     * Chave de comparação pelo nome do cliente sozinho: a mesma normalização, ignorando o número da frente
     * (o número só sai quando vem seguido de hífen, ponto ou espaço e de mais alguma coisa).
     */
    public static function normalizarCliente(string $nome): string
    {
        return trim((string) preg_replace(self::NUMERO_NA_FRENTE, '', self::normalizar($nome)));
    }

    private static function semAcento(string $nome): string
    {
        $decomposto = \Normalizer::normalize($nome, \Normalizer::FORM_D);
        if ($decomposto === false) {
            return $nome;
        }

        return (string) preg_replace('/\p{Mn}+/u', '', $decomposto);
    }
}
