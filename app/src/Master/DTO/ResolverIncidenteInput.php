<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/** Resolver incidente (POST /master/desempenho/incidentes/{id}/resolver, JSON `{solucao?: string}`). */
final readonly class ResolverIncidenteInput
{
    public function __construct(
        public ?User $ator,
        public int $id,
        public ?string $solucao = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, int $id, array $corpo): self
    {
        return new self($ator, $id, is_string($corpo['solucao'] ?? null) ? $corpo['solucao'] : null);
    }
}
