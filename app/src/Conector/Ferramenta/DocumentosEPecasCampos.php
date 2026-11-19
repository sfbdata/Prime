<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;

/**
 * Os campos da entrada da `documentos_e_pecas` e a conferência deles por operação (6.2, regra 10): campo que a operação
 * não usa é recusado, o que ela exige precisa vir, cada valor tem de ser do tipo certo e dentro do tamanho. A mensagem
 * é a fixa de {@see ErroDaFerramenta::entradaInvalida()}: nunca o valor mandado.
 *
 * Tipos: `id` (inteiro de 1 a 9 dígitos), `ids` (lista de ids sem repetição, com o máximo dado), `bool`, `texto`
 * (tamanho máximo e se pode ficar vazio) e `opcao` (uma das dadas).
 */
final class DocumentosEPecasCampos
{
    /** @var array<string, array{0: string, 1?: int|list<string>, 2?: bool}> */
    public const TIPOS = [
        'documento_id' => ['id'],
        'documentos' => ['ids', DocumentosEPecasAcoes::TETO_DO_LOTE],
        'subpastas' => ['ids', DocumentosEPecasAcoes::TETO_DO_LOTE],
        'subpasta_id' => ['id'],
        'destino_id' => ['id'],
        'pai_id' => ['id'],
        'alvo_id' => ['id'],
        'item' => ['opcao', ['documento', 'subpasta']],
        'marcado' => ['bool'],
        'nome' => ['texto', 255],
        'ids' => ['ids', 500],
        'categoria' => ['texto', 40],
        'descricao' => ['texto', 1000, true],
        'numero' => ['texto', 100, true],
        'nome_base' => ['texto', 200],
        'titulo' => ['texto', 255],
        'conteudo' => ['texto', 300_000],
        'vinculo_id' => ['id'],
        'modelo_id' => ['id'],
        'materia' => ['texto', 40],
        'tipo' => ['texto', 40],
        'corpo' => ['texto', 300_000],
        'item_id' => ['id'],
        'concluido' => ['bool'],
        'ativo' => ['bool'],
        'motivo' => ['texto', 40, true],
        'modelo_checklist_id' => ['id'],
    ];

    private function __construct()
    {
    }

    /**
     * Os campos da operação, conferidos e convertidos. `acao`, `operacao` e `pasta_id` já saíram de `$argumentos`.
     *
     * @param array{proposta: bool, obrigatorios: list<string>, opcionais: list<string>, um_de: list<string>, exclusivo?: bool, proposta_com?: string} $regras
     * @param array<string, mixed>                                                                                                                      $argumentos
     *
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    public static function conferir(array $regras, array $argumentos): array
    {
        $permitidos = [...$regras['obrigatorios'], ...$regras['opcionais']];
        if (array_diff(array_keys($argumentos), $permitidos) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        foreach ($regras['obrigatorios'] as $campo) {
            if (!\array_key_exists($campo, $argumentos)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        $campos = [];
        foreach ($argumentos as $campo => $valor) {
            $campos[$campo] = self::converter((string) $campo, $valor);
        }
        self::conferirUmDe($regras, $campos);
        if (\count($campos['documentos'] ?? []) + \count($campos['subpastas'] ?? []) > DocumentosEPecasAcoes::TETO_DO_LOTE) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $campos;
    }

    /**
     * @param array{um_de: list<string>, exclusivo?: bool} $regras
     * @param array<string, mixed>                         $campos
     */
    private static function conferirUmDe(array $regras, array $campos): void
    {
        if ($regras['um_de'] === []) {
            return;
        }
        $presentes = \count(array_intersect($regras['um_de'], array_keys($campos)));
        if ($presentes === 0 || (($regras['exclusivo'] ?? false) && $presentes > 1)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
    }

    private static function converter(string $campo, mixed $valor): mixed
    {
        return self::convertido(self::TIPOS[$campo] ?? throw ErroDaFerramenta::entradaInvalida(), $valor);
    }

    /** @param array<int|string, mixed> $tipo */
    private static function convertido(array $tipo, mixed $valor): mixed
    {
        return match ($tipo[0]) {
            'id' => self::inteiro($valor) ?? throw ErroDaFerramenta::entradaInvalida(),
            'ids' => self::ids($valor, (int) ($tipo[1] ?? 0)),
            'bool' => \is_bool($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            'texto' => self::texto($valor, (int) ($tipo[1] ?? 0), ($tipo[2] ?? false) === true),
            default => \is_string($valor) && \is_array($tipo[1] ?? null) && \in_array($valor, $tipo[1], true) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
        };
    }

    private static function inteiro(mixed $valor): ?int
    {
        if (\is_string($valor) && ctype_digit($valor) && \strlen($valor) <= 9) {
            $valor = (int) $valor;
        }

        return \is_int($valor) && $valor >= 1 && $valor <= 999_999_999 ? $valor : null;
    }

    /**
     * @return list<int>
     *
     * @throws ErroDaFerramenta
     */
    private static function ids(mixed $valor, int $maximo): array
    {
        if (!\is_array($valor) || $valor === [] || !array_is_list($valor) || \count($valor) > $maximo) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $ids = [];
        foreach ($valor as $item) {
            $id = self::inteiro($item) ?? throw ErroDaFerramenta::entradaInvalida();
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    private static function texto(mixed $valor, int $maximo, bool $podeFicarVazio): string
    {
        if (!\is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $texto = trim($valor);
        if (mb_strlen($texto) > $maximo || ($texto === '' && !$podeFicarVazio)) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $texto;
    }
}
