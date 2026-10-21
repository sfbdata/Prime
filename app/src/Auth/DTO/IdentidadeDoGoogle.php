<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/** Quem o Google disse que é, depois de conferido o `id_token` (e-mail sempre confirmado pelo Google). */
final class IdentidadeDoGoogle
{
    public function __construct(
        public readonly string $sub,
        public readonly string $email,
        public readonly string $nome,
    ) {
    }
}
