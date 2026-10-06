<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que {@see \App\Pasta\Service\SugestoesDeLimpeza} achou nos arquivos de UMA pasta.
 *
 *  - `identicoA`: id => id de outro arquivo com o MESMO conteúdo (sha256). Para quem deve sair,
 *    aponta o que fica (o mais antigo); para o que fica, aponta o segundo mais antigo. Quem não
 *    tem cópia idêntica não aparece.
 *  - `nomeParecidoCom`: id => o arquivo de nome mais parecido (≥ 85%), com o percentual. Só
 *    INFORMA ("confira se é o mesmo documento"); nunca entra em `grupos`.
 *  - `grupos`: as sugestões de limpeza por regra, na ordem do desenho, só as que têm arquivo,
 *    SÓ com os arquivos da raiz (a faixa só aparece lá, como no desenho). `ids` são os arquivos
 *    sugeridos para excluir; `bytes`, quanto eles somam.
 *  - `regraDe`: id => regra, em qualquer nível — o selo de sugestão da linha.
 *
 * "Idêntico" e "nome parecido" comparam só arquivos do MESMO nível (seção).
 */
final readonly class SugestoesDeLimpezaOutput
{
    public const IDENTICO       = 'identico';
    public const VAZIO          = 'vazio';
    public const COPIA_PROCESSO = 'copia_processo';
    public const MUITO_GRANDE   = 'muito_grande';

    /**
     * @param array<int, int>                                                       $identicoA
     * @param array<int, array{id: int, percentual: int}>                           $nomeParecidoCom
     * @param list<array{regra: string, ids: list<int>, bytes: int, rotulo: string}> $grupos
     * @param array<int, string>                                                    $regraDe
     */
    public function __construct(
        public array $identicoA,
        public array $nomeParecidoCom,
        public array $grupos,
        public array $regraDe = [],
    ) {
    }

    public static function nada(): self
    {
        return new self([], [], [], []);
    }
}
