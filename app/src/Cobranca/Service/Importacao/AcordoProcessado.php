<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * O que a importação fez (ou faria) com UMA aba de acordo. Existe para o dry-run poder imprimir a tabela
 * do §1 da spec — por acordo, com unidade e sacado — em vez de uma lista solta de números: o produto
 * principal desta entrega é a conferência ANTES da escrita, e um total agregado não permite conferir.
 */
final class AcordoProcessado
{
    /**
     * @param list<string> $parcelasCriadas        NNs de parcela que não existiam e serão/foram criadas
     * @param list<string> $parcelasExistentes     NNs de parcela que já existiam (no-op)
     * @param list<string> $contasMarcadas         NNs de conta original existente marcada como substituída
     * @param list<string> $contasReconstruidas    NNs de conta original ausente, criada JÁ substituída (§3.2.1)
     * @param list<string> $contasJaMarcadas       NNs que já tinham `acordoSubstituto` (idempotência)
     * @param list<string> $divergenciasDeValor    "NN: sistema R$ x, planilha R$ y" — reportado, NUNCA aplicado
     * @param list<string> $casadasSemCompetencia  NNs casados pelo fallback legado (obrigação sem competência
     *                                             gravada). Casou pelo NN sozinho: o operador precisa conferir
     * @param list<string> $contasRecusadas        contas originais que a importação se RECUSOU a marcar, com o
     *                                             motivo — parcela de outro acordo (INV-I) ou já substituída por
     *                                             um acordo diferente. Recusar em silêncio seria tão ruim quanto
     *                                             aceitar: o operador precisa saber que a planilha e o sistema
     *                                             discordam sobre o que é dívida original
     * @param list<string> $parcelasAmbiguas       NNs de parcela que NÃO foram criados porque o mesmo NN já existe
     *                                             no caso com OUTRA competência. Criar adicionaria dinheiro ao
     *                                             saldo a partir de um casamento duvidoso — a direção perigosa
     * @param list<string> $parcelasVinculadas     NNs de parcela que JÁ existiam soltas e passaram a apontar para o
     *                                             acordo (`acordoOrigem`). Não mexe em dinheiro — mas sem o vínculo
     *                                             a parcela não sai do saldo quando o acordo é rompido (invariável
     *                                             20), então a dívida ficaria contada duas vezes no dia do
     *                                             rompimento. É escrita, e por isso aparece na prévia
     * @param list<string> $parcelasLiquidadasIgnoradas NNs de parcela que a planilha diz PAGA e que não existem no
     *                                             sistema: NÃO foram criadas. Criá-las abriria dívida vencida
     *                                             para cobrar de novo o que já foi pago; dar baixa está fora
     *                                             de escopo (§5). Fica para conferência humana
     */
    public function __construct(
        public readonly int $numero,
        public readonly string $unidade,
        public readonly string $sacado,
        public readonly ?string $ignoradoPorque,
        public readonly array $parcelasCriadas,
        public readonly array $parcelasExistentes,
        public readonly array $contasMarcadas,
        public readonly array $contasReconstruidas,
        public readonly array $contasJaMarcadas,
        public readonly array $divergenciasDeValor,
        public readonly array $casadasSemCompetencia,
        public readonly array $contasRecusadas,
        public readonly array $parcelasAmbiguas,
        public readonly int $principalReconciliadoCentavos,
        public readonly int $valorParcelasCriadasCentavos,
        public readonly ?string $situacaoDivergente,
        public readonly array $parcelasVinculadas = [],
        public readonly array $parcelasLiquidadasIgnoradas = [],
    ) {
    }

    /** Aba pulada por completo (acordo inexistente, caso encerrado): nenhuma escrita saiu daqui. */
    public function foiIgnorado(): bool
    {
        return $this->ignoradoPorque !== null;
    }
}
