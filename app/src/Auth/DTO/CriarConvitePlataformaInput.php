<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

readonly class CriarConvitePlataformaInput
{
    public function __construct(
        public string $email,
        public ?string $fullName,
        public User $criadoPor,
    ) {}
}
