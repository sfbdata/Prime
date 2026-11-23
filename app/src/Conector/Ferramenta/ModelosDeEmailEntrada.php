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
    /** O teto de cada campo de texto, em caracteres (o `html`, em bytes): o mesmo do esquema e do DTO da mensagem. */
    public const LIMITES = ['nome' => 200, 'assunto' => 255, 'selo' => 120, 'titulo' => 255, 'corpo' => 20000, 'cta' => 120, 'link' => 2000, 'html' => 150000];
    public const MAXIMO_DE_ITENS = 30;
    public const TAMANHO_DO_ITEM = 500;
    public const MAXIMO_DE_BLOCOS = 60;
    public const TAMANHO_DO_BLOCO_A = 20000;
    public const TAMANHO_DO_BLOCO_B = 2000;
    /** O que a pessoa manda numa edição, em bytes do JSON, tem de caber nos dados da proposta. */
    public const TETO_DA_EDICAO = 170000;

    public const PASSOU_DO_LIMITE = 'O campo %s passa do limite de %d %s. Encurte e peça de novo.';
    public const EDICAO_GRANDE_DEMAIS = 'O que foi enviado passa do limite de 170 mil caracteres por edição. Edite em partes.';

    private const CAMPOS_DA_ACAO = [
        ModelosDeEmailFerramenta::CRIAR => [],
        ModelosDeEmailFerramenta::DUPLICAR => ['modelo'],
        ModelosDeEmailFerramenta::SALVAR_COMO => ['modelo', 'nome'],
        ModelosDeEmailFerramenta::EDITAR => ['modelo', 'nome', 'assunto', 'selo', 'titulo', 'corpo', 'itens', 'cta', 'link', 'blocos', 'html'],
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
        self::exigirDentroDosLimites($argumentos);

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

    /**
     * Valor acima do limite do esquema é recusado com o nome do campo e o limite, nunca cortado em silêncio.
     *
     * @param array<string, mixed> $campos
     *
     * @throws ErroDaFerramenta
     */
    private static function exigirDentroDosLimites(array $campos): void
    {
        foreach (self::LIMITES as $campo => $limite) {
            if (isset($campos[$campo]) && ($campo === 'html' ? \strlen($campos[$campo]) : mb_strlen($campos[$campo])) > $limite) {
                throw ErroDaFerramenta::recusada(sprintf(self::PASSOU_DO_LIMITE, $campo, $limite, $campo === 'html' ? 'bytes' : 'caracteres'));
            }
        }
        if (isset($campos['itens'])) {
            self::exigirItens($campos['itens']);
        }
        if (isset($campos['blocos'])) {
            self::exigirBlocos($campos['blocos']);
        }
        if (\strlen(json_encode($campos, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '') > self::TETO_DA_EDICAO) {
            throw ErroDaFerramenta::recusada(self::EDICAO_GRANDE_DEMAIS);
        }
    }

    /** @param array<mixed> $itens */
    private static function exigirItens(array $itens): void
    {
        if (\count($itens) > self::MAXIMO_DE_ITENS) {
            throw ErroDaFerramenta::recusada(sprintf(self::PASSOU_DO_LIMITE, 'itens', self::MAXIMO_DE_ITENS, 'itens'));
        }
        foreach ($itens as $item) {
            if (!\is_string($item)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
            if (mb_strlen($item) > self::TAMANHO_DO_ITEM) {
                throw ErroDaFerramenta::recusada(sprintf(self::PASSOU_DO_LIMITE, 'itens (cada item)', self::TAMANHO_DO_ITEM, 'caracteres'));
            }
        }
    }

    /** @param array<mixed> $blocos */
    private static function exigirBlocos(array $blocos): void
    {
        if (\count($blocos) > self::MAXIMO_DE_BLOCOS) {
            throw ErroDaFerramenta::recusada(sprintf(self::PASSOU_DO_LIMITE, 'blocos', self::MAXIMO_DE_BLOCOS, 'blocos'));
        }
        foreach ($blocos as $bloco) {
            foreach (['a' => self::TAMANHO_DO_BLOCO_A, 'b' => self::TAMANHO_DO_BLOCO_B] as $parte => $limite) {
                if (isset($bloco[$parte]) && (!\is_string($bloco[$parte]) || mb_strlen($bloco[$parte]) > $limite)) {
                    throw ErroDaFerramenta::recusada(sprintf(self::PASSOU_DO_LIMITE, 'blocos (campo ' . $parte . ' de cada bloco)', $limite, 'caracteres'));
                }
            }
        }
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
