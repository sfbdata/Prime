<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

use App\Entity\Auth\User;

final readonly class DemitirFuncionarioInput
{
    public function __construct(
        public User  $executor,
        public User  $funcionario,
        public ?User $substituto = null,
    ) {}
}
