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
        public readonly \DateTimeImmutable $dataAcordo,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly bool $vigente,
        public readonly bool $ativo,
        public readonly bool $casoEncerrado,
        public readonly ?string $motivoRompimento,
        public readonly ?string $motivoCancelamento,
        public readonly int $valorTotalNegociado,
        public readonly int $valorEntrada,
        public readonly int $valorSubstituidas,
        public readonly int $valorDesconto,
        public readonly bool $temJuros,
        public readonly int $totalAlocado,
        public readonly array $parcelas,
        public readonly array $substituidas,
    ) {
    }
}
