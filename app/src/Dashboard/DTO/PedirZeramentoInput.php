<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** "Pedir para zerar" (DASH-014): quem não pode zerar pede ao primário e à Controladoria. `pessoaId` nulo = a equipe toda. */
final class PedirZeramentoInput
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $solicitante,
        public readonly string $coluna,
        public readonly ?int $pessoaId,
        public readonly \DateTimeImmutable $agora,
    ) {
    }
}
