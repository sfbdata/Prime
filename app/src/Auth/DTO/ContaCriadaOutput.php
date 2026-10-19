<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * O que o cadastro devolve ao controller: a conta (para o login programático) e o escritório (para o contexto).
 */
final readonly class ContaCriadaOutput
{
    public function __construct(
        public User $user,
        public Tenant $tenant,
    ) {}
}
