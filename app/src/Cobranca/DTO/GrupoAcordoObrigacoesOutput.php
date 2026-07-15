<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Um acordo VIGENTE e suas parcelas, agrupados para a aba Obrigações (Ajuste 8).
 *
 * Antes as parcelas apareciam soltas no meio das demais obrigações; agora o acordo vira uma
 * linha-resumo que expande as suas parcelas e leva ao detalhe (`cobranca_acordo_show`, Ajuste 7).
 * Só acordo vigente vira grupo: parcela de acordo rompido/cancelado é histórico e segue na lista solta.
 *
 * `parcelas`/`qtdParcelas`/`valorTotal` cobrem só as parcelas VIVAS (exigíveis) — uma parcela deste
 * acordo que já tenha sido substituída por OUTRO acordo vigente está fora do saldo e por isso fica
 * fora do grupo (aparece no detalhe do acordo, travada). Assim o total do resumo bate com o saldo
 * derivado. `qtdSubstituidas` é o que ESTE acordo substituiu (do snapshot do `AcordoOutput`).
 * `valorTotal` em CENTAVOS = Σ do exigível das parcelas vivas (derivado, não é o snapshot do acordo).
 */
final class GrupoAcordoObrigacoesOutput
{
    /**
     * @param list<ObrigacaoOutput> $parcelas
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
    ) {
    }
}
