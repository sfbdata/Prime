<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\RevisaoPessoaCobrada;

/**
 * Leitura de uma Revisão de Pessoa Cobrada (SPEC §8) para o bloco de atenção do detalhe (Etapa 8).
 * Enquanto pendente, alimenta o alerta de revisão; depois de resolvida, cessa (invariável §8).
 */
final class RevisaoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $motivo,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly bool $pendente,
        public readonly ?string $resolucao,
        public readonly \DateTimeImmutable $criadoEm,
        public readonly ?\DateTimeImmutable $resolvidaEm,
        public readonly ?string $resolvidaPorNome,
    ) {
    }

    public static function fromEntity(RevisaoPessoaCobrada $r): self
    {
        $resolvidaPor = $r->getResolvidaPor();

        return new self(
            id: $r->getId() ?? 0,
            motivo: $r->getMotivo(),
            statusLabel: $r->getStatus()->label(),
            statusBadgeClass: $r->getStatus()->badgeClass(),
            pendente: $r->estaPendente(),
            resolucao: $r->getResolucao(),
            criadoEm: $r->getCriadoEm(),
            resolvidaEm: $r->getResolvidaEm(),
            resolvidaPorNome: $resolvidaPor?->getFullName() ?? $resolvidaPor?->getEmail(),
        );
    }
}
