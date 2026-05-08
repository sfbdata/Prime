<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;

readonly class CriarConviteEscritorioInput
{
    public function __construct(
        public string $email,
        public ?string $fullName,
        public Tenant $tenant,
        public TenantRole $tenantRole,
        public User $criadoPor,
    ) {}
}
