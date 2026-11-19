<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Agenda\DTO\CompromissoInput;
use App\Conector\Exception\ErroDaFerramenta;

/**
 * A entrada da `gravar_na_agenda` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 13), conferida contra o esquema da
 * ferramenta: a `acao` da {@see TabelaDeAbaEChave}, os campos que cada ação aceita e os que exige, e o tipo e o
 * tamanho de cada campo. Campo fora da lista da ação, texto onde vai número e valor fora do enum são pedido inválido.
 */
final class GravarNaAgendaEntrada
{
    public const ACOES = ['salvar', 'mover', 'status', 'avisar'];

    /** Os tipos do modal da Agenda ({@see CompromissoInput}); `meus` é o "Compromisso" comum. */
    public const TIPOS = ['meus', 'aud', 'reun', 'prazo', 'urg', 'pess', 'lemb'];
    public const VISIBILIDADES = ['equipe', 'so_eu'];

    /** `excluido` não é uma situação do compromisso: é o pedido de excluir, que vira proposta. */
    public const STATUS = ['agendado', 'concluido', 'cancelado', 'excluido'];

    public const MAXIMO_DE_PESSOAS = 50;
    public const TAMANHO_DO_TITULO = 255;
    public const TAMANHO_DA_OBSERVACAO = 5000;

    /** Os campos que cada ação aceita além de `acao`. */
    private const CAMPOS = [
        'salvar' => ['compromisso_id', 'titulo', 'tipo', 'data', 'dia_inteiro', 'inicio', 'fim', 'dias', 'visibilidade', 'pasta_id', 'participantes', 'lembrete_minutos', 'observacao'],
        'mover' => ['compromisso_id', 'data', 'hora'],
        'status' => ['compromisso_id', 'status'],
        'avisar' => ['compromisso_id', 'prazo_id', 'colegas'],
    ];

    private const OBRIGATORIOS = [
        'mover' => ['compromisso_id', 'data'],
        'status' => ['compromisso_id', 'status'],
        'avisar' => ['colegas'],
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @return array{0: string, 1: array<string, mixed>} a ação e os campos, já com o tipo certo
     *
     * @throws ErroDaFerramenta
     */
    public static function ler(array $argumentos): array
    {
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !\in_array($acao, self::ACOES, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        unset($argumentos['acao']);
        if (array_diff(array_keys($argumentos), self::CAMPOS[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        foreach (self::OBRIGATORIOS[$acao] ?? [] as $campo) {
            if (!\array_key_exists($campo, $argumentos)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }

        $campos = [];
        foreach ($argumentos as $campo => $valor) {
            $campos[$campo] = self::campo($campo, $valor);
        }
        self::exigirCombinacao($acao, $campos);

        return [$acao, $campos];
    }

    /**
     * Criar exige título e data; avisar, o compromisso OU o prazo (um dos dois, nunca os dois).
     *
     * @param array<string, mixed> $campos
     *
     * @throws ErroDaFerramenta
     */
    private static function exigirCombinacao(string $acao, array $campos): void
    {
        if ($acao === 'salvar' && !isset($campos['compromisso_id']) && (!isset($campos['titulo']) || !isset($campos['data']))) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        if ($acao === 'avisar' && isset($campos['compromisso_id']) === isset($campos['prazo_id'])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        if ($acao === 'avisar' && $campos['colegas'] === []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
    }

    /**
     * @return string|int|bool|list<int>|null
     *
     * @throws ErroDaFerramenta
     */
    private static function campo(string $campo, mixed $valor): string|int|bool|array|null
    {
        return match ($campo) {
            'compromisso_id', 'prazo_id' => self::inteiro($valor) ?? throw ErroDaFerramenta::entradaInvalida(),
            // `null` tira a pasta do compromisso que se edita.
            'pasta_id' => $valor === null ? null : (self::inteiro($valor) ?? throw ErroDaFerramenta::entradaInvalida()),
            'dia_inteiro' => \is_bool($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            'participantes', 'colegas' => self::ids($valor),
            'dias' => \is_int($valor) && $valor >= 0 && $valor <= 366 ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            'lembrete_minutos' => \is_int($valor) && \in_array($valor, CompromissoInput::LEMBRETES, true) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            'titulo' => self::texto($valor, self::TAMANHO_DO_TITULO, false),
            'observacao' => self::texto($valor, self::TAMANHO_DA_OBSERVACAO, true),
            'tipo' => self::dentroDe($valor, self::TIPOS),
            'visibilidade' => self::dentroDe($valor, self::VISIBILIDADES),
            'status' => self::dentroDe($valor, self::STATUS),
            'data' => self::data($valor),
            'inicio', 'hora' => self::hora($valor, false),
            default => self::hora($valor, true),
        };
    }

    private static function inteiro(mixed $valor): ?int
    {
        // Só inteiro, como diz o esquema: o texto "1" é pedido inválido.
        return \is_int($valor) && $valor >= 1 && $valor <= 999_999_999 ? $valor : null;
    }

    /**
     * @return list<int>
     *
     * @throws ErroDaFerramenta
     */
    private static function ids(mixed $valor): array
    {
        if (!\is_array($valor) || !array_is_list($valor) || \count($valor) > self::MAXIMO_DE_PESSOAS) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $ids = array_map(static fn (mixed $id): int => self::inteiro($id) ?? throw ErroDaFerramenta::entradaInvalida(), $valor);

        return array_values(array_unique($ids));
    }

    /** @throws ErroDaFerramenta */
    private static function texto(mixed $valor, int $maximo, bool $podeVazio): string
    {
        $texto = \is_string($valor) ? trim($valor) : null;
        if ($texto === null || mb_strlen($texto) > $maximo || (!$podeVazio && $texto === '')) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $texto;
    }

    /**
     * @param list<string> $lista
     *
     * @throws ErroDaFerramenta
     */
    private static function dentroDe(mixed $valor, array $lista): string
    {
        return \is_string($valor) && \in_array($valor, $lista, true) ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }

    /** @throws ErroDaFerramenta */
    private static function data(mixed $valor): string
    {
        $lida = \is_string($valor) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;

        return $lida !== false && $lida->format('Y-m-d') === $valor ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }

    /**
     * A hora HH:MM; o fim aceita vazio (um compromisso sem fim dura uma hora).
     *
     * @throws ErroDaFerramenta
     */
    private static function hora(mixed $valor, bool $podeVazio): string
    {
        if (\is_string($valor) && (($podeVazio && $valor === '') || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor) === 1)) {
            return $valor;
        }

        throw ErroDaFerramenta::entradaInvalida();
    }
}
