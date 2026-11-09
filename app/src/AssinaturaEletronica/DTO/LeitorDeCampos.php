<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Exception\AcaoRecusada;

/**
 * Leitura tolerante dos campos do JSON que a tela manda: ausente ou nulo vira o padrão, número vira texto, e o que
 * não é escalar (lista, objeto) é recusado em vez de virar "Array".
 */
final class LeitorDeCampos
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    public static function texto(array $json, string $campo, string $padrao = '', bool $aparar = true): string
    {
        $valor = $json[$campo] ?? null;
        if ($valor === null) {
            return $padrao;
        }

        if (!\is_string($valor) && !\is_int($valor) && !\is_float($valor)) {
            throw new AcaoRecusada('assinatura_eletronica.erro.dados_invalidos');
        }

        return $aparar ? trim((string) $valor) : (string) $valor;
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    public static function textoOuNulo(array $json, string $campo, bool $aparar = true): ?string
    {
        $valor = self::texto($json, $campo, '', $aparar);

        return trim($valor) === '' ? null : $valor;
    }

    /** @param array<mixed> $json */
    public static function booleano(array $json, string $campo): bool
    {
        return self::booleanoOuNulo($json, $campo) ?? false;
    }

    /**
     * Ausente ou nulo devolve nulo (quem chama distingue "não veio" de "veio falso"); o resto vira booleano.
     *
     * @param array<mixed> $json
     */
    public static function booleanoOuNulo(array $json, string $campo): ?bool
    {
        $valor = $json[$campo] ?? null;
        if ($valor === null) {
            return null;
        }

        return filter_var($valor, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Inteiro do JSON (ou texto só de dígitos). Qualquer outra coisa devolve nulo, e quem chama decide o erro.
     *
     * @param array<mixed> $json
     */
    public static function inteiroOuNulo(array $json, string $campo): ?int
    {
        $valor = $json[$campo] ?? null;
        if (\is_int($valor)) {
            return $valor;
        }

        if (\is_string($valor) && preg_match('/^-?\d{1,9}$/', $valor) === 1) {
            return (int) $valor;
        }

        return null;
    }

    /**
     * @param array<string, int|string> $parametros
     *
     * @throws AcaoRecusada
     */
    public static function exigirTamanho(?string $valor, int $maximo, string $chave, array $parametros = []): void
    {
        if ($valor !== null && mb_strlen($valor) > $maximo) {
            throw new AcaoRecusada($chave, $parametros);
        }
    }
}
