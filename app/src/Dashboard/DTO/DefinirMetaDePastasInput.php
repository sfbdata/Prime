<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Definir a meta de pastas do mês (DASH-016). Escritório e autor são SEMPRE os da sessão; `agora`
 * decide o mês de vigência (o mês corrente).
 */
final class DefinirMetaDePastasInput
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $autor,
        public readonly int $valor,
        public readonly \DateTimeImmutable $agora,
    ) {
    }
}
