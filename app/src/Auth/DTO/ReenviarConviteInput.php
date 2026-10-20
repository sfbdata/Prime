<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

readonly class ReenviarConviteInput
{
    /** @param User $reenviadoPor quem pede o reenvio; com o e-mail a confirmar, o reenvio é recusado */
    public function __construct(
        public string $token,
        public User $reenviadoPor,
    ) {}
}
