<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

readonly class RecusarConviteEscritorioInput
{
    public function __construct(
        public string $token,
        public User $usuarioAtual,
    ) {}
}
