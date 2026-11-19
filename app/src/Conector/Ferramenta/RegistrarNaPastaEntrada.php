<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;

/**
 * Os argumentos da `registrar_na_pasta` já conferidos na FORMA (`docs/specs/mcp-remoto-oauth.md`, 6.2, regra 10): o
 * lugar, a ação, o `pasta_id`, só os campos que a ação aceita, os obrigatórios dela e cada um com o tipo certo. O VALOR
 * (texto dentro do tamanho, cor da paleta, colega ativo) é conferido depois das recusas de acesso, pela ferramenta.
 */
final class RegistrarNaPastaEntrada
{
    public const ACOES = ['escrever', 'responder', 'editar', 'destacar', 'excluir'];

    /** O tamanho máximo do texto que chega (o UseCase conta o texto visível, até 5000). */
    public const TAMANHO_DO_TEXTO = 5_000;

    /** O teto de menções por registro, o mesmo do Registro ({@see \App\Pasta\Service\MencoesDoRegistro::MAXIMO_POR_REGISTRO}). */
    public const MAXIMO_DE_MENCOES = 20;

    /** Tipo de cada campo: `int`, `bool`, `ids` (lista de ids) ou o tamanho máximo do texto. */
    private const TIPOS_DOS_CAMPOS = [
        'registro_id' => 'int',
        'texto' => self::TAMANHO_DO_TEXTO,
        'mencoes' => 'ids',
        'cor' => 20,
        'tirar' => 'bool',
    ];

    private const CAMPOS_DA_ACAO = [
        'escrever' => ['texto', 'mencoes'],
        'responder' => ['registro_id', 'texto', 'mencoes'],
        'editar' => ['registro_id', 'texto', 'mencoes'],
        'destacar' => ['registro_id', 'cor', 'tirar'],
        'excluir' => ['registro_id'],
    ];

    private const OBRIGATORIOS = [
        'escrever' => ['texto'],
        'responder' => ['registro_id', 'texto'],
        'editar' => ['registro_id', 'texto'],
        'destacar' => ['registro_id'],
        'excluir' => ['registro_id'],
    ];

    /** @param array<string, mixed> $campos */
    private function __construct(
        public readonly string $lugar,
        public readonly string $acao,
        public readonly int $pastaId,
        private readonly array $campos,
    ) {
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    public static function de(array $argumentos): self
    {
        $lugar = $argumentos['lugar'] ?? null;
        $acao = $argumentos['acao'] ?? null;
        if (!\in_array($lugar, RegistrarNaPastaLugares::LUGARES, true) || !\is_string($acao) || !isset(self::CAMPOS_DA_ACAO[$acao])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pastaId = self::inteiro($argumentos['pasta_id'] ?? null) ?? throw ErroDaFerramenta::entradaInvalida();
        unset($argumentos['lugar'], $argumentos['acao'], $argumentos['pasta_id']);
        if (array_diff(array_keys($argumentos), self::CAMPOS_DA_ACAO[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $campos = [];
        foreach ($argumentos as $campo => $valor) {
            $campos[(string) $campo] = self::valorDoCampo((string) $campo, $valor);
        }
        if (array_diff(self::OBRIGATORIOS[$acao], array_keys($campos)) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return new self($lugar, $acao, $pastaId, $campos);
    }

    public function tem(string $campo): bool
    {
        return \array_key_exists($campo, $this->campos);
    }

    public function numero(string $campo): ?int
    {
        $valor = $this->campos[$campo] ?? null;

        return \is_int($valor) ? $valor : null;
    }

    /** O texto como veio, sem espaço nas pontas; null se o campo não veio. */
    public function texto(string $campo): ?string
    {
        $valor = $this->campos[$campo] ?? null;

        return \is_string($valor) ? trim($valor) : null;
    }

    public function marcado(string $campo): bool
    {
        return ($this->campos[$campo] ?? false) === true;
    }

    /** @return list<int> sem repetição, na ordem em que vieram */
    public function ids(string $campo): array
    {
        $valor = $this->campos[$campo] ?? [];

        return \is_array($valor) ? array_values(array_unique(array_filter($valor, '\is_int'))) : [];
    }

    /** @throws ErroDaFerramenta */
    private static function valorDoCampo(string $campo, mixed $valor): mixed
    {
        $tipo = self::TIPOS_DOS_CAMPOS[$campo];
        if (\is_int($tipo)) {
            return \is_string($valor) && mb_strlen($valor) <= $tipo ? $valor : throw ErroDaFerramenta::entradaInvalida();
        }

        return match ($tipo) {
            'int' => self::inteiro($valor) ?? throw ErroDaFerramenta::entradaInvalida(),
            'bool' => \is_bool($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            default => self::lista($valor),
        };
    }

    /**
     * @return list<int>
     *
     * @throws ErroDaFerramenta
     */
    private static function lista(mixed $valor): array
    {
        if (!\is_array($valor) || !array_is_list($valor) || \count($valor) > self::MAXIMO_DE_MENCOES) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return array_map(static fn (mixed $id): int => self::inteiro($id) ?? throw ErroDaFerramenta::entradaInvalida(), $valor);
    }

    private static function inteiro(mixed $valor): ?int
    {
        if (\is_string($valor) && ctype_digit($valor) && \strlen($valor) <= 9) {
            $valor = (int) $valor;
        }

        return \is_int($valor) && $valor >= 1 ? $valor : null;
    }
}
