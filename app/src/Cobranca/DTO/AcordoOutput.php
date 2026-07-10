<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Acordo;

/**
 * Leitura de um Acordo para a aba Acordos do Caso (Etapa 8). Estado com badge (`badgeClass()` do
 * enum); contagens de obrigações substituídas × parcelas para o Twig resumir sem iterar coleções.
 */
final class AcordoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly \DateTimeImmutable $dataAcordo,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly bool $vigente,
        public readonly ?string $motivoRompimento,
        public readonly ?string $motivoCancelamento,
        public readonly int $qtdObrigacoesSubstituidas,
        public readonly int $qtdParcelas,
    ) {
    }

    public static function fromEntity(Acordo $a): self
    {
        return new self(
            id: $a->getId() ?? 0,
            dataAcordo: $a->getDataAcordo(),
            statusLabel: $a->getStatus()->label(),
            statusBadgeClass: $a->getStatus()->badgeClass(),
            vigente: $a->getStatus()->ehVigente(),
            motivoRompimento: $a->getMotivoRompimento(),
            motivoCancelamento: $a->getMotivoCancelamento(),
            qtdObrigacoesSubstituidas: $a->getObrigacoesSubstituidas()->count(),
            qtdParcelas: $a->getParcelas()->count(),
        );
    }
}
