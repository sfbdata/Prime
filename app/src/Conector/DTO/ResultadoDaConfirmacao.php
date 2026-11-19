<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * O que um {@see \App\Conector\Ferramenta\ExecutorDeProposta} devolve depois de gravar a ação confirmada
 * (`docs/specs/mcp-remoto-oauth.md`, 6.7, itens 5 e 6):
 *  - {@see $dados}: o que a `confirmar_acao` devolve ao app de I.A. (num lote, o resultado de cada item: criado,
 *    excluído, recusado e por quê), com as mesmas regras de toda resposta (6.2, regras 4, 6 e 7);
 *  - {@see $idsDoResultado}: os ids do que foi feito, sem texto, que ficam na proposta depois de zerada;
 *  - {@see $contagens} e {@see $ids}: para o log `mcp`, como em {@see RespostaDaFerramenta}.
 */
final class ResultadoDaConfirmacao
{
    /**
     * @param array<string, mixed>     $dados
     * @param list<int>                $idsDoResultado
     * @param array<string, int>       $contagens
     * @param array<string, list<int>> $ids
     */
    public function __construct(
        public readonly array $dados,
        public readonly array $idsDoResultado = [],
        public readonly array $contagens = [],
        public readonly array $ids = [],
    ) {
    }
}
