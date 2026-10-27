<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;

/** "Apagar da lista": o Master confirma digitando APAGAR (POST /master/recompensas/usuarios/{id}/apagar, corpo `{confirmacao}`). */
final readonly class ApagarIndicadorDaListaInput
{
    public function __construct(
        /** Quem apaga: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public int $userId,
        public string $confirmacao,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, int $userId, array $corpo): self
    {
        return new self($ator, $userId, is_string($corpo['confirmacao'] ?? null) ? $corpo['confirmacao'] : '');
    }
}
