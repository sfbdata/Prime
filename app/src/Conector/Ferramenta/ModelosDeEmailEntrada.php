<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;

/**
 * A entrada da `modelos_de_email` já conferida: a ação, o modelo (id, `cu<id>` ou chave de um padrão, normalizado para
 * `cu<id>` ou a chave) e os campos que a ação aceita. Campo de outra ação ou de tipo errado é entrada inválida.
 */
final class ModelosDeEmailEntrada
{
    /** Os campos de texto que a edição troca; `nome` só vale no modelo próprio. */
    public const CAMPOS_DE_TEXTO = ['nome', 'assunto', 'selo', 'titulo', 'corpo', 'cta', 'link'];

    private const CAMPOS_DA_ACAO = [
        ModelosDeEmailFerramenta::CRIAR => [],
        ModelosDeEmailFerramenta::DUPLICAR => ['modelo'],
        ModelosDeEmailFerramenta::SALVAR_COMO => ['modelo', 'nome'],
        ModelosDeEmailFerramenta::EDITAR => ['modelo', 'nome', 'assunto', 'selo', 'titulo', 'corpo', 'itens', 'cta', 'link', 'blocos'],
        ModelosDeEmailFerramenta::VOLTAR_AO_PADRAO => ['modelo'],
        ModelosDeEmailFerramenta::EXCLUIR => ['modelo'],
    ];

    /** @param array<string, mixed> $campos */
    private function __construct(
        public readonly string $acao,
        public readonly ?string $modelo,
        public readonly array $campos,
    ) {
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    public static function deArgumentos(array $argumentos): self
    {
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !isset(self::CAMPOS_DA_ACAO[$acao])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        unset($argumentos['acao']);
        if (array_diff(array_keys($argumentos), self::CAMPOS_DA_ACAO[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $modelo = null;
        if (\in_array('modelo', self::CAMPOS_DA_ACAO[$acao], true)) {
            $modelo = self::chaveDoModelo($argumentos['modelo'] ?? null);
        }
        unset($argumentos['modelo']);
        foreach ($argumentos as $campo => $valor) {
            if (!self::tipoValido($campo, $valor)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        if ($acao === ModelosDeEmailFerramenta::SALVAR_COMO && !isset($argumentos['nome'])) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return new self($acao, $modelo, $argumentos);
    }

    /** `12`, `cu12` ou a chave de um padrão (`convite`, `data-natal`): vira `cu12` ou a chave. */
    private static function chaveDoModelo(mixed $valor): string
    {
        if (!\is_string($valor)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $valor = trim($valor);
        if (preg_match('/^(?:cu)?(\d{1,9})$/', $valor, $partes) === 1) {
            return 'cu' . $partes[1];
        }

        return preg_match('/^[a-z0-9-]{1,40}$/', $valor) === 1 ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }

    private static function tipoValido(string $campo, mixed $valor): bool
    {
        return match ($campo) {
            'itens' => \is_array($valor) && array_is_list($valor),
            'blocos' => \is_array($valor) && array_is_list($valor) && array_filter($valor, static fn (mixed $b): bool => !\is_array($b)) === [],
            default => \is_string($valor),
        };
    }
}
