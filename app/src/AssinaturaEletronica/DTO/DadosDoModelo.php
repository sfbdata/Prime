<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Service\ValidadorDoModelo;

/**
 * A `Entrada` do "Salvar modelo" (contrato da etapa 4, seções 5 e 6), já conferida. O nome, a categoria, o formato e
 * o texto lateral são lidos aqui; campos, signatários e blocos, no {@see ValidadorDoModelo}. Toda recusa vira 422
 * com o texto da regra que falhou; nada é cortado.
 */
final readonly class DadosDoModelo
{
    public const NOME_MAXIMO = 200;
    public const CATEGORIA_MAXIMO = 80;
    /** A categoria do desenho para o modelo sem categoria (dc:454). */
    public const CATEGORIA_PADRAO = 'Meus modelos';
    public const LATERAL_MAXIMO = 20000;
    public const LAYOUTS = ['', '2col'];
    /** As chaves da `Entrada`: qualquer outra (um `html`, por exemplo) recusa o pedido. */
    public const CHAVES = ['nome', 'categoria', 'layout', 'lateral', 'folhaBranca', 'campos', 'signatarios', 'corpo'];
    private const ERRO = 'assinatura_eletronica.modelo.erro.';

    /**
     * @param list<list<int|string|list<string>>> $campos
     * @param list<array{papel: string}>           $signatarios
     * @param list<array<string, int|string>>      $corpo
     */
    private function __construct(
        public string $nome,
        public string $categoria,
        public string $layout,
        public ?string $lateral,
        public bool $folhaBranca,
        public array $campos,
        public array $signatarios,
        public array $corpo,
    ) {
    }

    /**
     * @param mixed $json o corpo do pedido já decodificado
     *
     * @throws AcaoRecusada
     */
    public static function deJson(mixed $json): self
    {
        if (!\is_array($json) || (array_is_list($json) && $json !== []) || array_diff(array_keys($json), self::CHAVES) !== []) {
            throw new AcaoRecusada(self::ERRO . 'dados_invalidos');
        }

        $nome = self::nome($json);
        $categoria = self::limitado($json, 'categoria', self::CATEGORIA_MAXIMO, 'categoria_longa') ?? self::CATEGORIA_PADRAO;
        $layout = self::layout($json);
        $lateral = self::limitado($json, 'lateral', self::LATERAL_MAXIMO, 'lateral_longa', aparar: false);
        $campos = ValidadorDoModelo::campos($json['campos'] ?? []);
        $signatarios = ValidadorDoModelo::signatarios($json['signatarios'] ?? []);
        $corpo = ValidadorDoModelo::corpo($json['corpo'] ?? [], \count($signatarios));
        ValidadorDoModelo::exigirOEssencial($corpo, \count($signatarios), $layout);

        return new self($nome, $categoria, $layout, $lateral, self::folhaBranca($json), $campos, $signatarios, $corpo);
    }

    /**
     * Só booleano (ausente ou nulo é falso): "sim", 1 ou lista recusam, em vez de virar falso em silêncio.
     *
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function folhaBranca(array $json): bool
    {
        $valor = $json['folhaBranca'] ?? false;
        if (!\is_bool($valor)) {
            throw new AcaoRecusada(self::ERRO . 'dados_invalidos');
        }

        return $valor;
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function nome(array $json): string
    {
        $nome = self::limitado($json, 'nome', self::NOME_MAXIMO, 'nome_longo');
        if ($nome === null) {
            throw new AcaoRecusada(self::ERRO . 'nome_vazio');
        }

        return $nome;
    }

    /**
     * Texto do JSON (ausente, nulo ou só espaço devolve nulo) que não passa do limite.
     *
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function limitado(array $json, string $campo, int $maximo, string $erro, bool $aparar = true): ?string
    {
        $valor = $json[$campo] ?? null;
        if ($valor !== null && !\is_string($valor)) {
            throw new AcaoRecusada(self::ERRO . 'dados_invalidos');
        }

        if ($valor === null || trim($valor) === '') {
            return null;
        }

        $valor = $aparar ? trim($valor) : $valor;
        LeitorDeCampos::exigirTamanho($valor, $maximo, self::ERRO . $erro);

        return $valor;
    }

    /**
     * @param array<mixed> $json
     *
     * @throws AcaoRecusada
     */
    private static function layout(array $json): string
    {
        $layout = $json['layout'] ?? '';
        if (!\in_array($layout, self::LAYOUTS, true)) {
            throw new AcaoRecusada(self::ERRO . 'layout_invalido');
        }

        return $layout;
    }
}
