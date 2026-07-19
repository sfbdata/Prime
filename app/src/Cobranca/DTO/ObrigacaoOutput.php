<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Obrigacao;

/**
 * Leitura de uma Obrigação para a seção "Dívida em aberto" da página do objeto (Etapa 8; a partir do
 * ajuste 10, essa seção fundiu as antigas abas Obrigações e Acordos numa fila única). Dinheiro em
 * centavos int (formatado no Twig com `|centavos`); `valorAtual` = original + encargos reconhecidos
 * (SPEC §10).
 * Sinaliza (vigente-aware) se a obrigação foi substituída por acordo vigente (sai do saldo, invariável
 * 15), se é parcela de acordo vigente, ou se é parcela de acordo rompido/cancelado (`parcelaDeAcordoDesfeito`
 * — histórico, fora do saldo) — para o Twig marcar visualmente e liberar/travar a edição sem reimplementar a regra.
 */
final class ObrigacaoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $descricao,
        public readonly int $valorOriginal,
        public readonly int $encargosReconhecidos,
        public readonly int $valorAtual,
        public readonly \DateTimeImmutable $vencimentoOriginal,
        public readonly ?string $referenciaExterna,
        public readonly bool $substituidaPorAcordo,
        public readonly bool $ehParcelaAcordo,
        public readonly bool $parcelaDeAcordoDesfeito,
        /** Acordo que gerou esta obrigação (null = não é parcela) — agrupa os grupos de acordo dentro da
         * seção "Dívida em aberto" (Ajuste 8; grupos consolidados no Ajuste 10). */
        public readonly ?int $acordoOrigemId = null,
        /** Acordo que substituiu esta obrigação (null = não substituída) — agrupa as trocadas (Ajuste 10). */
        public readonly ?int $acordoSubstitutoId = null,
        /**
         * Σ das alocações de pagamento nesta obrigação (centavos) — DERIVADO (invariável 20), nunca coluna.
         * Carregado em LOTE pelo UseCase (`somasPorObrigacaoDosCasos`); default 0 mantém os chamadores antigos.
         */
        public readonly int $alocado = 0,
        /**
         * Valor BRUTO a cobrar para quitar o `restante` desta obrigação (centavos) — já com os honorários
         * acrescidos quando a forma é `acrescido_divida` (Ajuste 10, spec §5.1). É o prefill do "Receber":
         * o alvo é invisível ao gestor (quitar R$1.200 exige digitar R$1.320). Calculado no SERVIDOR, pelo
         * UseCase, que é quem conhece o snapshot de honorários do caso — o DTO continua burro.
         */
        public readonly int $brutoSugerido = 0,
        /**
         * Encargos SEPARADOS materializados (centavos) — as colunas do relatório da contabilidade
         * (spec "encargos configuráveis em cascata" §11). `encargosReconhecidos` acima continua
         * sendo a soma de juros+multa+correcao (INV-E1); honorários ficam FORA do `valorAtual`
         * (INV-E2), aparecem só na linha. Defaults 0 preservam os chamadores antigos.
         */
        public readonly int $juros = 0,
        public readonly int $multa = 0,
        public readonly int $correcao = 0,
        public readonly int $honorarios = 0,
        /** Preenchido = encargos congelados, param de crescer (INV-E4); a UI marca isso. */
        public readonly ?\DateTimeImmutable $encargosCongeladosEm = null,
        /** Data de referência da última materialização — o "atualizado em" da tela. */
        public readonly ?\DateTimeImmutable $encargosAtualizadosEm = null,
    ) {
    }

    /** Total exibido na linha do relatório: exigível MAIS honorários (que não entram no saldo). */
    public function totalComHonorarios(): int
    {
        return $this->valorAtual + $this->honorarios;
    }

    /**
     * Quanto ainda falta receber nesta obrigação (centavos), com PISO 0: alocação manual não tem teto por
     * obrigação, então uma super-alocada devolveria negativo e poluiria a tela (spec §10, ajuste 10).
     */
    public function restante(): int
    {
        return max(0, $this->valorAtual - $this->alocado);
    }

    /** Alocado cobre o exigível — espelha `ParcelaAcordoResumoOutput::quitada`. */
    public function quitada(): bool
    {
        return $this->alocado >= $this->valorAtual;
    }

    public static function fromEntity(Obrigacao $o, int $alocado = 0, int $brutoSugerido = 0): self
    {
        $substituto = $o->getAcordoSubstituto();
        $origem = $o->getAcordoOrigem();

        return new self(
            id: $o->getId() ?? 0,
            descricao: $o->getDescricao(),
            valorOriginal: $o->getValorOriginal(),
            encargosReconhecidos: $o->getEncargosReconhecidos(),
            // A fórmula do exigível mora na ENTIDADE (INV-E1). Aqui era replicada — e replicar
            // fórmula de dinheiro é como as duas versões divergem sem ninguém notar.
            valorAtual: $o->valorExigivel(),
            vencimentoOriginal: $o->getVencimentoOriginal(),
            referenciaExterna: $o->getReferenciaExterna(),
            // Vigente-aware: só marca/trava quando o acordo está ATIVO/CUMPRIDO. Acordo rompido/cancelado
            // solta a original (volta ao saldo) e vira a parcela em histórico (`parcelaDeAcordoDesfeito`).
            substituidaPorAcordo: $substituto !== null && $substituto->getStatus()->ehVigente(),
            ehParcelaAcordo: $origem !== null && $origem->getStatus()->ehVigente(),
            parcelaDeAcordoDesfeito: $origem !== null && !$origem->getStatus()->ehVigente(),
            acordoOrigemId: $origem?->getId(),
            acordoSubstitutoId: $substituto?->getId(),
            alocado: $alocado,
            brutoSugerido: $brutoSugerido,
            juros: $o->getJuros(),
            multa: $o->getMulta(),
            correcao: $o->getCorrecao(),
            honorarios: $o->getHonorarios(),
            encargosCongeladosEm: $o->getEncargosCongeladosEm(),
            encargosAtualizadosEm: $o->getEncargosAtualizadosEm(),
        );
    }
}
