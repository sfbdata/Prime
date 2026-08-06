<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Um acordo VIGENTE e suas parcelas, agrupados para a seção "Dívida em aberto" da página do objeto
 * (Ajuste 8; a partir do Ajuste 10 essa seção fundiu as antigas abas Obrigações e Acordos).
 *
 * Antes as parcelas apareciam soltas no meio das demais obrigações; agora o acordo vira uma
 * linha-resumo que expande as suas parcelas e leva ao detalhe (`cobranca_acordo_show`, Ajuste 7).
 * Só acordo vigente vira grupo: parcela de acordo rompido/cancelado é histórico e segue na lista solta
 * (seção "Acordos encerrados", Ajuste 10).
 *
 * `parcelas`/`qtdParcelas`/`valorTotal` cobrem só as parcelas VIVAS (exigíveis) — uma parcela deste
 * acordo que já tenha sido substituída por OUTRO acordo vigente está fora do saldo e por isso fica
 * fora do grupo (aparece no detalhe do acordo, travada). Assim o total do resumo bate com o saldo
 * derivado. `qtdSubstituidas` é o que ESTE acordo substituiu (do snapshot do `AcordoOutput`).
 * `valorTotal` em CENTAVOS = Σ do exigível das parcelas vivas (derivado, não é o snapshot do acordo).
 * `substituidas` traz as próprias obrigações que ESTE acordo tirou do saldo — ficam recolhidas na tela
 * (Ajuste 10) e voltam ao exigível por derivação se o acordo for rompido/cancelado.
 */
final class GrupoAcordoObrigacoesOutput
{
    /**
     * @param list<ObrigacaoOutput> $parcelas
     * @param list<ObrigacaoOutput> $substituidas
     */
    public function __construct(
        public readonly int $acordoId,
        public readonly \DateTimeImmutable $dataAcordo,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly int $qtdParcelas,
        public readonly int $qtdSubstituidas,
        public readonly int $valorTotal,
        public readonly array $parcelas,
        /**
         * As obrigações que ESTE acordo tirou do saldo (Ajuste 10). Ficam recolhidas na tela: voltam ao
         * exigível por derivação se o acordo for rompido/cancelado. NÃO entram em `valorTotal` — estão
         * fora do saldo, e somá-las divergiria do saldo derivado.
         */
        public readonly array $substituidas = [],
        /**
         * Id do acordo que ASSUMIU este — derivado, igual ao do `AcordoOutput` (spec
         * `cobranca-acordo-assume-parcelas-do-anterior.md`).
         *
         * Um acordo já assumido ainda vira grupo quando sobraram parcelas dele **pagas**: elas não foram
         * substituídas, então continuam no grupo e o acordo não cai em "Acordos encerrados". Sem este
         * campo ele apareceria na seção Dívida com o selo "Ativo" — que é exatamente o que o dono pediu
         * para parar de acontecer. Medido: 29 acordos nessa forma, contra 8 que não têm parcela nenhuma
         * sobrando.
         */
        public readonly ?int $substituidoPeloAcordoId = null,
    ) {
    }
}
