<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

final readonly class DemitirFuncionarioInput
{
    public function __construct(
        public User    $executor,
        public User    $funcionario,
        public Tenant  $tenant,
        public ?User   $substituto = null,
    ) {}
}
