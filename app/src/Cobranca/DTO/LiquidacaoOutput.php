<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Liquidacao;

/**
 * Leitura de uma Liquidação não monetária para a aba Pagamentos & Liquidações (Etapa 8).
 * `valorReconhecido` (≠ valor atribuído ao bem, SPEC §11) é o que reduz o saldo. Dinheiro em centavos int.
 */
final class LiquidacaoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly \DateTimeImmutable $data,
        public readonly string $tipoLabel,
        public readonly string $descricaoBem,
        public readonly ?int $valorAtribuidoBem,
        public readonly int $valorReconhecido,
    ) {
    }

    public static function fromEntity(Liquidacao $l): self
    {
        return new self(
            id: $l->getId() ?? 0,
            data: $l->getData(),
            tipoLabel: $l->getTipo()->label(),
            descricaoBem: $l->getDescricaoBem(),
            valorAtribuidoBem: $l->getValorAtribuidoBem(),
            valorReconhecido: $l->getValorReconhecido(),
        );
    }
}
