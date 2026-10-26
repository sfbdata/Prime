<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Remover (encerrar) a meta de pastas a partir do mês corrente (DASH-016). Escritório e autor são os da sessão. */
final class RemoverMetaDePastasInput
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $autor,
        public readonly \DateTimeImmutable $agora,
    ) {
    }
}
