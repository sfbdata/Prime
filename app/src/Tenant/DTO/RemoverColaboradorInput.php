<?php
declare(strict_types=1);

namespace App\Tenant\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

final readonly class RemoverColaboradorInput
{
    public function __construct(
        public User $executor,
        public User $colaborador,
        public Tenant $tenant,
        public ?User $substituto = null,
        public OrigemRemocao $origem = OrigemRemocao::Painel,
    ) {
    }
}
