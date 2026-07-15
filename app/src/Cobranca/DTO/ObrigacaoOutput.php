<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Obrigacao;

/**
 * Leitura de uma Obrigação para a aba Obrigações do Caso (Etapa 8). Dinheiro em centavos int
 * (formatado no Twig com `|centavos`); `valorAtual` = original + encargos reconhecidos (SPEC §10).
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
        /** Acordo que gerou esta obrigação (null = não é parcela) — agrupa a aba Obrigações (Ajuste 8). */
        public readonly ?int $acordoOrigemId = null,
    ) {
    }

    public static function fromEntity(Obrigacao $o): self
    {
        $substituto = $o->getAcordoSubstituto();
        $origem = $o->getAcordoOrigem();

        return new self(
            id: $o->getId() ?? 0,
            descricao: $o->getDescricao(),
            valorOriginal: $o->getValorOriginal(),
            encargosReconhecidos: $o->getEncargosReconhecidos(),
            valorAtual: $o->getValorOriginal() + $o->getEncargosReconhecidos(),
            vencimentoOriginal: $o->getVencimentoOriginal(),
            referenciaExterna: $o->getReferenciaExterna(),
            // Vigente-aware: só marca/trava quando o acordo está ATIVO/CUMPRIDO. Acordo rompido/cancelado
            // solta a original (volta ao saldo) e vira a parcela em histórico (`parcelaDeAcordoDesfeito`).
            substituidaPorAcordo: $substituto !== null && $substituto->getStatus()->ehVigente(),
            ehParcelaAcordo: $origem !== null && $origem->getStatus()->ehVigente(),
            parcelaDeAcordoDesfeito: $origem !== null && !$origem->getStatus()->ehVigente(),
            acordoOrigemId: $origem?->getId(),
        );
    }
}
