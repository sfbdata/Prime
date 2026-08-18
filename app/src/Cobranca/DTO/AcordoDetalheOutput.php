<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Detalhe de um Acordo (Ajuste 7, Fatia 3) — leitura da tela `cobranca_acordo_show`.
 *
 * Dinheiro em CENTAVOS int (Twig formata com `|centavos`). `valorTotalNegociado` e `valorEntrada`
 * saem do SNAPSHOT da negociação gravado no Acordo (descritivo, não-autoritativo para saldo);
 * acordos anteriores ao Ajuste 7 têm snapshot nulo e caem no total DERIVADO (Σ parcelas).
 * `valorDesconto` é SEMPRE derivado (Σ substituídas − total): positivo = desconto, negativo = juros
 * (`temJuros`). O saldo do caso continua derivado pelos serviços — esta tela não o recalcula.
 *
 * `estaIncompleto`/`parcelasFaltantes` (spec `cobranca-importar-linhas-acordo.md` §3.3, tarefa #7-B):
 * acordo importado de um relatório com "Parc. p/t" e t > 1 só traz a(s) parcela(s) presente(s) — as
 * faltantes entram por importação futura (quando vencerem) ou à mão. Sempre `false`/`0` para acordo
 * manual (sem `numeroParcelasTotal`).
 */
final class AcordoDetalheOutput
{
    /**
     * `ativo` + `casoEncerrado` alimentam a barra de ações do detalhe (Fatia 4): só acordo ATIVO em
     * caso aberto aceita Editar/Romper/Cancelar/Cumprir — mesma condição que o UseCase revalida.
     *
     * @param list<ParcelaAcordoResumoOutput>          $parcelas
     * @param list<ObrigacaoSubstituidaResumoOutput>   $substituidas
     */
    public function __construct(
        public readonly int $id,
        public readonly int $objetoId,
        /** Anulável: a contabilidade pode não ter a data. NUNCA renderizar com `|date` sem guarda —
         *  `{{ null|date('d/m/Y') }}` imprime a data de HOJE (verificado 17/08). */
        public readonly ?\DateTimeImmutable $dataAcordo,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly bool $vigente,
        public readonly bool $ativo,
        public readonly bool $casoEncerrado,
        public readonly ?string $motivoRompimento,
        public readonly ?string $motivoCancelamento,
        public readonly int $valorTotalNegociado,
        public readonly int $valorEntrada,
        /**
         * Σ das substituídas. **NULL = não apurável**: alguma substituída está com o encargo não
         * calculado (acordo sem data), e somar o resíduo delas daria um número inventado com cara de
         * conta. O vazio é a resposta honesta — quem julga a falta é a gerência.
         */
        public readonly ?int $valorSubstituidas,
        /** Derivado de `valorSubstituidas`; **NULL pela mesma razão** — desconto sobre resíduo é ficção. */
        public readonly ?int $valorDesconto,
        /** Sem `valorDesconto` não há sinal para declarar; `false` quando não apurável. */
        public readonly bool $temJuros,
        public readonly int $totalAlocado,
        public readonly bool $estaIncompleto,
        public readonly int $parcelasFaltantes,
        public readonly array $parcelas,
        public readonly array $substituidas,
    ) {
    }
}
