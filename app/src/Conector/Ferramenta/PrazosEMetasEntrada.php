<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;

/**
 * Os argumentos da `prazos_e_metas_da_pasta` já conferidos na FORMA (`docs/specs/mcp-remoto-oauth.md`, 6.2, regra 10):
 * a ação, o `pasta_id`, só os campos que a ação aceita e cada um com o tipo certo. O VALOR (data que existe, tipo de
 * prazo, colega do escritório) é conferido depois das recusas de acesso, pela ferramenta.
 */
final class PrazosEMetasEntrada
{
    public const ACOES = ['salvar_prazo', 'cumprir_prazo', 'analisar_prazo_da_meta', 'criar_meta'];

    /** Tipo de cada campo: `int`, `bool`, `ids` (lista de ids) ou o tamanho máximo do texto. */
    public const TIPOS_DOS_CAMPOS = [
        'prazo_id' => 'int',
        'tipo' => 20,
        'texto' => 1_000,
        'data' => 10,
        'hora' => 5,
        'responsavel_id' => 'int',
        'excluir' => 'bool',
        'titulo' => 300,
        'descricao' => 10_000,
        'prazo' => 10,
        'responsaveis' => 'ids',
        'etapa' => 30,
        'data_publicacao' => 10,
        'data_ciencia' => 10,
        'prazo_juridico' => 10,
        'confirmado' => 'bool',
    ];

    private const CAMPOS_DA_ACAO = [
        'salvar_prazo' => ['prazo_id', 'tipo', 'texto', 'data', 'hora', 'responsavel_id'],
        'cumprir_prazo' => ['prazo_id', 'excluir'],
        'analisar_prazo_da_meta' => ['titulo', 'descricao', 'data_publicacao', 'data_ciencia', 'prazo_juridico', 'confirmado'],
        'criar_meta' => ['titulo', 'descricao', 'prazo', 'responsaveis', 'etapa', 'data_publicacao', 'data_ciencia', 'prazo_juridico', 'confirmado'],
    ];

    private const OBRIGATORIOS = ['cumprir_prazo' => 'prazo_id', 'analisar_prazo_da_meta' => 'titulo'];

    private const MAXIMO_DE_RESPONSAVEIS = 20;

    /** @param array<string, mixed> $campos */
    private function __construct(
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
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !isset(self::CAMPOS_DA_ACAO[$acao])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pastaId = self::inteiro($argumentos['pasta_id'] ?? null) ?? throw ErroDaFerramenta::entradaInvalida();
        unset($argumentos['acao'], $argumentos['pasta_id']);
        if (array_diff(array_keys($argumentos), self::CAMPOS_DA_ACAO[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $campos = [];
        foreach ($argumentos as $campo => $valor) {
            $campos[$campo] = self::valorDoCampo((string) $campo, $valor);
        }
        $obrigatorio = self::OBRIGATORIOS[$acao] ?? null;
        if ($obrigatorio !== null && !isset($campos[$obrigatorio])) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return new self($acao, $pastaId, $campos);
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

    /** @return list<int> */
    public function ids(string $campo): array
    {
        $valor = $this->campos[$campo] ?? [];

        return \is_array($valor) ? array_values(array_filter($valor, '\is_int')) : [];
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
        if (!\is_array($valor) || !array_is_list($valor) || \count($valor) > self::MAXIMO_DE_RESPONSAVEIS) {
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
