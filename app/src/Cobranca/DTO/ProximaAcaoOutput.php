<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\ProximaAcao;

/**
 * Leitura da Próxima Ação do Caso (SPEC §14) para o destaque "próxima coisa a fazer" no detalhe
 * (Etapa 8). `atrasada` é derivado do prazo vs. hoje (calculado na origem e passado pronto).
 */
final class ProximaAcaoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $descricao,
        public readonly ?\DateTimeImmutable $prazo,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly bool $pendente,
        public readonly bool $atrasada,
        public readonly ?string $resultado,
        public readonly ?string $responsavelNome,
        public readonly ?\DateTimeImmutable $concluidaEm,
    ) {
    }

    public static function fromEntity(ProximaAcao $a, \DateTimeImmutable $hoje): self
    {
        $responsavel = $a->getResponsavel();

        return new self(
            id: $a->getId() ?? 0,
            descricao: $a->getDescricao(),
            prazo: $a->getPrazo(),
            statusLabel: $a->getStatus()->label(),
            statusBadgeClass: $a->getStatus()->badgeClass(),
            pendente: $a->estaPendente(),
            atrasada: $a->estaPendente() && $a->estaAtrasada($hoje),
            resultado: $a->getResultado(),
            responsavelNome: $responsavel?->getFullName() ?? $responsavel?->getEmail(),
            concluidaEm: $a->getConcluidaEm(),
        );
    }
}
