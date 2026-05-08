<?php

declare(strict_types=1);

namespace App\Auth\DTO;

readonly class RevogarConviteInput
{
    public function __construct(
        public string $token,
    ) {}
}
