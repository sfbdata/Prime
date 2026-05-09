<?php

declare(strict_types=1);

namespace App\Auth\DTO;

readonly class ReenviarConviteInput
{
    public function __construct(
        public string $token,
    ) {}
}
