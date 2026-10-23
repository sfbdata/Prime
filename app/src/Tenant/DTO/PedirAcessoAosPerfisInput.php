<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

final readonly class PedirAcessoAosPerfisInput
{
    public function __construct(
        public Tenant $tenant,
        /** Quem pede: o colaborador que caiu na tela "Acesso restrito" dos Perfis. */
        public User $solicitante,
    ) {
    }
}
