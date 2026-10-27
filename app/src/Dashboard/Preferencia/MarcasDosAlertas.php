<?php

declare(strict_types=1);

namespace App\Dashboard\Preferencia;

use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Inteligencia\MotorAvancado;

/**
 * As marcas pessoais que um usuário põe nos alertas da Central de Inteligência (dc L967-974 e
 * L2542-2556): "Resolvido" / "Ignorar" (o alerta fica esmaecido e desce na lista) e o retorno
 * "A recomendação se aplica?" (Aplicar / Não se aplica).
 *
 * Formato guardado: `{ "<id do alerta>": { "status": "resolvido"|"ignorado"|null, "feedback": "sim"|"nao"|null } }`.
 * Só ids que a Central emite (MotorAvancado::IDS) e só esses valores; marca vazia não é guardada.
 * Tocar de novo na marca ativa a desfaz, como o `marcar()` do desenho.
 */
final class MarcasDosAlertas
{
    /** Chave da linha em `preferencia_usuario` (fora do catálogo do menu ⋮: tem endpoint próprio). */
    public const CHAVE = 'dashboard.ia_alertas';

    public const RESOLVIDO = 'resolvido';
    public const IGNORADO  = 'ignorado';
    public const SIM       = 'sim';
    public const NAO       = 'nao';

    private const STATUS   = [self::RESOLVIDO, self::IGNORADO];
    private const FEEDBACK = [self::SIM, self::NAO];

    /** @return list<string> as ações que o endpoint aceita */
    public static function acoes(): array
    {
        return [...self::STATUS, ...self::FEEDBACK];
    }

    /**
     * @return array<string, array{status: ?string, feedback: ?string}>
     *
     * @throws PreferenciaInvalidaException
     */
    public static function normalizar(mixed $valor): array
    {
        if (!is_array($valor) || ($valor !== [] && array_is_list($valor))) {
            throw new PreferenciaInvalidaException('As marcas dos alertas vêm num mapa por alerta.');
        }

        $mapa = [];
        foreach ($valor as $id => $marca) {
            $id = (string) $id;
            if (!in_array($id, MotorAvancado::IDS, true) || !is_array($marca)) {
                throw new PreferenciaInvalidaException('Alerta desconhecido.');
            }
            $status   = self::campo($marca['status'] ?? null, self::STATUS);
            $feedback = self::campo($marca['feedback'] ?? null, self::FEEDBACK);
            if ($status !== null || $feedback !== null) {
                $mapa[$id] = ['status' => $status, 'feedback' => $feedback];
            }
        }
        ksort($mapa);

        return $mapa;
    }

    /**
     * Lê o que o banco devolveu sem quebrar a tela: marca inválida (id que saiu da Central) é descartada.
     *
     * @return array<string, array{status: ?string, feedback: ?string}>
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
     * @param array<string, array{status: ?string, feedback: ?string}> $mapa
     *
     * @return array<string, array{status: ?string, feedback: ?string}>
     *
     * @throws PreferenciaInvalidaException
     */
    public static function alternar(array $mapa, string $alerta, string $acao): array
    {
        if (!in_array($alerta, MotorAvancado::IDS, true)) {
            throw new PreferenciaInvalidaException('Alerta desconhecido.');
        }
        if (!in_array($acao, self::acoes(), true)) {
            throw new PreferenciaInvalidaException('Ação desconhecida.');
        }

        $atual = $mapa[$alerta] ?? ['status' => null, 'feedback' => null];
        $campo = in_array($acao, self::STATUS, true) ? 'status' : 'feedback';
        $atual[$campo] = $atual[$campo] === $acao ? null : $acao;

        $mapa[$alerta] = $atual;

        return self::normalizar($mapa);
    }

    /** @param list<string> $permitidos */
    private static function campo(mixed $valor, array $permitidos): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (!is_string($valor) || !in_array($valor, $permitidos, true)) {
            throw new PreferenciaInvalidaException('Marca de alerta não permitida.');
        }

        return $valor;
    }
}
