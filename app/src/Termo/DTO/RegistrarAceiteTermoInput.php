<?php

declare(strict_types=1);

namespace App\Termo\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Termo\Enum\OrigemDoAceite;

final readonly class RegistrarAceiteTermoInput
{
    public function __construct(
        public User $user,
        public string $ip,
        public ?string $userAgent = null,
        public ?Tenant $tenant = null,
        public OrigemDoAceite $origem = OrigemDoAceite::Cadastro,
    ) {}
}
