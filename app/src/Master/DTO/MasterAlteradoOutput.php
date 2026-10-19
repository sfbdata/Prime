<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/** Quem foi promovido a Master secundário ou deixou de ser Master: base da mensagem de sucesso. */
final readonly class MasterAlteradoOutput
{
    public function __construct(
        public int $userId,
        public string $nome,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self((int) $user->getId(), (string) $user->getFullName());
    }
}
