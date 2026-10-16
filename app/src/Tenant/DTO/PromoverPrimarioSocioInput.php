<?php
declare(strict_types=1);

namespace App\Tenant\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

final readonly class PromoverPrimarioSocioInput
{
    public function __construct(
        public Tenant $tenant,
        public User $alvo,
    ) {
    }
}
