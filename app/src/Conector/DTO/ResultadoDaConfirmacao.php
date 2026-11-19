<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * O que um {@see \App\Conector\Ferramenta\ExecutorDeProposta} devolve depois de gravar a ação confirmada
 * (`docs/specs/mcp-remoto-oauth.md`, 6.7, itens 5 e 6):
 *  - {@see $dados}: o que a `confirmar_acao` devolve ao app de I.A. (num lote, o resultado de cada item: criado,
 *    excluído, recusado e por quê), com as mesmas regras de toda resposta (6.2, regras 4, 6 e 7);
 *  - {@see $idsDoResultado}: os ids do que foi feito, sem texto, que ficam na proposta depois de zerada;
 *  - {@see $contagens} e {@see $ids}: para o log `mcp`, como em {@see RespostaDaFerramenta};
 *  - {@see $depoisDoCommit}: opcional, o passo que só pode rodar com a gravação já confirmada no banco (hoje, a remoção
 *    dos arquivos do "excluir de vez", INV-6). A `confirmar_acao` o roda logo depois do COMMIT e só se o COMMIT
 *    aconteceu: recusa, teto do resultado ou falha antes dele, e o passo nunca roda. Falha dentro do passo não muda a
 *    resposta nem desfaz nada (vira registro no log `mcp`); por isso ele mesmo não pode depender de lançar para avisar.
 *    Não leva entidade: depois do executor o EntityManager pode ter sido limpo.
 */
final class ResultadoDaConfirmacao
{
    /**
     * @param array<string, mixed>     $dados
     * @param list<int>                $idsDoResultado
     * @param array<string, int>       $contagens
     * @param array<string, list<int>> $ids
     * @param (\Closure(): void)|null  $depoisDoCommit
     */
    public function __construct(
        public readonly array $dados,
        public readonly array $idsDoResultado = [],
        public readonly array $contagens = [],
        public readonly array $ids = [],
        public readonly ?\Closure $depoisDoCommit = null,
    ) {
    }
}
