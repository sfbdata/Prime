<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\EventoHistorico;

/**
 * Leitura de um evento do Histórico operacional do Caso (SPEC §13) para a timeline do detalhe
 * (Etapa 8). Histórico operacional ≠ auditoria técnica (invariável 26); este é o log de domínio.
 * O payload `dados` NÃO é exposto na timeline (detalhe técnico); só tipo/descrição/quem/quando.
 */
final class EventoHistoricoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $tipoValue,
        public readonly string $tipoLabel,
        public readonly \DateTimeImmutable $ocorridoEm,
        public readonly ?string $usuarioNome,
        public readonly string $descricao,
    ) {
    }

    public static function fromEntity(EventoHistorico $e): self
    {
        $usuario = $e->getUsuario();

        return new self(
            id: $e->getId() ?? 0,
            tipoValue: $e->getTipo()->value,
            tipoLabel: $e->getTipo()->label(),
            ocorridoEm: $e->getOcorridoEm(),
            usuarioNome: $usuario?->getFullName() ?? $usuario?->getEmail(),
            descricao: $e->getDescricao(),
        );
    }
}
