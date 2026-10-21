<?php

declare(strict_types=1);

namespace App\Master\DTO;

/**
 * Marcação em lote da Central do Master (os botões da barra e a estrela da linha): a ação e as mensagens da seleção,
 * pela chave "origem:id".
 */
final class MarcarNaCentralInput
{
    public const LIDA = 'lida';
    public const NAO_LIDA = 'nao_lida';
    public const ESTRELA = 'estrela';
    public const SEM_ESTRELA = 'sem_estrela';
    public const ARQUIVAR = 'arquivar';
    public const DESARQUIVAR = 'desarquivar';

    public const ACOES = [self::LIDA, self::NAO_LIDA, self::ESTRELA, self::SEM_ESTRELA, self::ARQUIVAR, self::DESARQUIVAR];

    /** Teto da seleção: uma página do desenho (50) com folga. */
    public const MAXIMO = 200;

    /** @param list<array{0: string, 1: int}> $mensagens pares [origem, id] */
    public function __construct(
        public readonly string $acao,
        public readonly array $mensagens,
    ) {
    }

    /**
     * Do corpo JSON `{acao, chaves: ["origem:id", ...]}`; nulo se a ação ou alguma chave não vale.
     *
     * @param array<mixed> $corpo
     */
    public static function doCorpo(array $corpo): ?self
    {
        $acao = $corpo['acao'] ?? null;
        $chaves = $corpo['chaves'] ?? null;
        if (!is_string($acao) || !in_array($acao, self::ACOES, true) || !is_array($chaves) || $chaves === [] || count($chaves) > self::MAXIMO) {
            return null;
        }
        $mensagens = [];
        foreach ($chaves as $chave) {
            if (!is_string($chave) || preg_match('/^([a-z_]{3,20}):(\d{1,10})$/', $chave, $m) !== 1) {
                return null;
            }
            $mensagens[$chave] = [$m[1], (int) $m[2]];
        }

        return new self($acao, array_values($mensagens));
    }
}
