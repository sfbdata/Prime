<?php

declare(strict_types=1);

namespace App\Dashboard\Preferencia;

use App\Dashboard\Exception\PreferenciaInvalidaException;

/**
 * As marcas pessoais que um gestor põe na análise individual de cada pessoa (dc L2457-2463):
 * "Marcar como analisado" e o retorno "Essa análise ajudou?" (Sim / Não / Parcialmente).
 *
 * Formato guardado em `preferencia_usuario`: `{ "<id da pessoa>": { "status": "analisado"|null, "feedback": "sim"|"nao"|"parcial"|null } }`.
 * Só esses valores; marca vazia não é guardada. Tocar de novo na marca ativa a desfaz (padrão de
 * {@see MarcasDosAlertas}). A posse (a pessoa é do escritório) é conferida pelo UseCase, não aqui.
 */
final class MarcasDasPessoas
{
    /** Chave da linha em `preferencia_usuario` (fora do catálogo do menu ⋮: tem endpoint próprio). */
    public const CHAVE = 'dashboard.ia_pessoas';

    public const ANALISADO = 'analisado';
    public const SIM       = 'sim';
    public const NAO       = 'nao';
    public const PARCIAL   = 'parcial';

    private const FEEDBACK = [self::SIM, self::NAO, self::PARCIAL];

    /** @return list<string> as ações que o endpoint aceita */
    public static function acoes(): array
    {
        return [self::ANALISADO, ...self::FEEDBACK];
    }

    /**
     * @return array<int, array{status: ?string, feedback: ?string}>
     *
     * @throws PreferenciaInvalidaException
     */
    public static function normalizar(mixed $valor): array
    {
        if (!is_array($valor) || ($valor !== [] && array_is_list($valor))) {
            throw new PreferenciaInvalidaException('As marcas das pessoas vêm num mapa por pessoa.');
        }

        $mapa = [];
        foreach ($valor as $id => $marca) {
            if (!ctype_digit((string) $id) || (int) $id <= 0 || !is_array($marca)) {
                throw new PreferenciaInvalidaException('Pessoa desconhecida.');
            }
            $status   = self::campo($marca['status'] ?? null, [self::ANALISADO]);
            $feedback = self::campo($marca['feedback'] ?? null, self::FEEDBACK);
            if ($status !== null || $feedback !== null) {
                $mapa[(int) $id] = ['status' => $status, 'feedback' => $feedback];
            }
        }
        ksort($mapa);

        return $mapa;
    }

    /**
     * Lê o que o banco devolveu sem quebrar a tela: marca inválida é descartada.
     *
     * @return array<int, array{status: ?string, feedback: ?string}>
     */
    public static function lerOuVazio(mixed $gravado): array
    {
        try {
            return self::normalizar($gravado ?? []);
        } catch (PreferenciaInvalidaException) {
            return [];
        }
    }

    /**
     * Aplica uma ação ao mapa: repetir a marca ativa a desfaz.
     *
     * @param array<int, array{status: ?string, feedback: ?string}> $mapa
     *
     * @return array<int, array{status: ?string, feedback: ?string}>
     *
     * @throws PreferenciaInvalidaException
     */
    public static function alternar(array $mapa, int $pessoaId, string $acao): array
    {
        if ($pessoaId <= 0) {
            throw new PreferenciaInvalidaException('Pessoa desconhecida.');
        }
        if (!in_array($acao, self::acoes(), true)) {
            throw new PreferenciaInvalidaException('Ação desconhecida.');
        }

        $chave = $pessoaId;
        $atual = $mapa[$chave] ?? ['status' => null, 'feedback' => null];
        $campo = $acao === self::ANALISADO ? 'status' : 'feedback';
        $atual[$campo] = $atual[$campo] === $acao ? null : $acao;

        $mapa[$chave] = $atual;

        return self::normalizar($mapa);
    }

    /** @param list<string> $permitidos */
    private static function campo(mixed $valor, array $permitidos): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (!is_string($valor) || !in_array($valor, $permitidos, true)) {
            throw new PreferenciaInvalidaException('Marca de pessoa não permitida.');
        }

        return $valor;
    }
}
