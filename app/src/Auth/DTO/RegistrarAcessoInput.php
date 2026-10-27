<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

/** Um login interativo bem-sucedido: quem entrou e o que o pedido revela do aparelho. */
final class RegistrarAcessoInput
{
    public function __construct(
        public readonly User $user,
        public readonly ?string $userAgent,
        public readonly ?string $ip,
    ) {
    }
}
