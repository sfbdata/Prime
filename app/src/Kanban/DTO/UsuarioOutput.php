<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Entity\Auth\User;

final class UsuarioOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $email,
        public readonly ?string $iniciais,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        $nome = $user->getFullName() ?? $user->getEmail() ?? '';
        $partes = explode(' ', $nome);
        $iniciais = mb_strtoupper(
            (mb_substr($partes[0] ?? '', 0, 1)) .
            (mb_substr($partes[array_key_last($partes)] ?? '', 0, 1))
        );

        return new self(
            id: $user->getId(),
            nome: $nome,
            email: $user->getEmail() ?? '',
            iniciais: $iniciais ?: null,
        );
    }
}
