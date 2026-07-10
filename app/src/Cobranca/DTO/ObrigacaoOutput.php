<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Obrigacao;

/**
 * Leitura de uma Obrigação para a aba Obrigações do Caso (Etapa 8). Dinheiro em centavos int
 * (formatado no Twig com `|centavos`); `valorAtual` = original + encargos reconhecidos (SPEC §10).
 * Sinaliza se a obrigação foi substituída por acordo vigente (sai do saldo, invariável 15) ou se
 * é parcela derivada de acordo — para o Twig marcar visualmente sem reimplementar a regra.
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
    ) {
    }

    public static function fromEntity(Obrigacao $o): self
    {
        $substituto = $o->getAcordoSubstituto();

        return new self(
            id: $o->getId() ?? 0,
            descricao: $o->getDescricao(),
            valorOriginal: $o->getValorOriginal(),
            encargosReconhecidos: $o->getEncargosReconhecidos(),
            valorAtual: $o->getValorOriginal() + $o->getEncargosReconhecidos(),
            vencimentoOriginal: $o->getVencimentoOriginal(),
            referenciaExterna: $o->getReferenciaExterna(),
            substituidaPorAcordo: $substituto !== null && $substituto->getStatus()->ehVigente(),
            ehParcelaAcordo: $o->getAcordoOrigem() !== null,
        );
    }
}
