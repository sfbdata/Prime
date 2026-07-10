<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Pagamento;

/**
 * Leitura de um Pagamento para a aba Pagamentos & Liquidações do Caso (Etapa 8). O pagamento
 * decompõe-se em dívida do credor / encargos / honorários do escritório (SPEC §18, centavos int).
 * `foiCorrigido` indica correção sem estorno (SPEC §22) — o Twig marca a origem sem lógica própria.
 */
final class PagamentoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly \DateTimeImmutable $data,
        public readonly int $valorDivida,
        public readonly int $valorEncargos,
        public readonly int $valorHonorarios,
        public readonly int $valorTotal,
        public readonly ?string $motivoCorrecao,
        public readonly bool $foiCorrigido,
    ) {
    }

    public static function fromEntity(Pagamento $p): self
    {
        return new self(
            id: $p->getId() ?? 0,
            data: $p->getData(),
            valorDivida: $p->getValorDivida(),
            valorEncargos: $p->getValorEncargos(),
            valorHonorarios: $p->getValorHonorarios(),
            valorTotal: $p->getValorDivida() + $p->getValorEncargos() + $p->getValorHonorarios(),
            motivoCorrecao: $p->getMotivoCorrecao(),
            foiCorrigido: $p->getMotivoCorrecao() !== null,
        );
    }
}
