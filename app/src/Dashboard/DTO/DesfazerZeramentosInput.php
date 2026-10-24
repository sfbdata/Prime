<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** "Desfazer zeramentos" de uma coluna (DASH-014): todos os zeramentos ativos dela, no escritório da sessão. */
final class DesfazerZeramentosInput
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $autor,
        public readonly string $coluna,
        public readonly \DateTimeImmutable $agora,
    ) {
    }
}
