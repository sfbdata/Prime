<?php
declare(strict_types=1);

namespace App\Tenant\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;

final readonly class RemoverPrimarioSocioInput
{
    public function __construct(
        public Tenant $tenant,
        public User $alvo,
        /** Perfil secundário que o sócio passa a ter. Nulo é recusado com mensagem. */
        public ?TenantRole $perfilDestino,
    ) {
    }
}
