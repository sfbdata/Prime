<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;

/**
 * Linha da tela de revisão de OAB do super-admin. `User` global (sem tenant).
 */
final class OabReviewOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $email,
        public readonly ?string $oabNumero,
        public readonly ?string $oabUf,
        public readonly ?StatusOab $oabStatus,
        public readonly ?string $oabNomeOficial,
        public readonly ?\DateTimeImmutable $oabVerificadaEm,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self(
            id: (int) $user->getId(),
            nome: (string) $user->getFullName(),
            email: (string) $user->getEmail(),
            oabNumero: $user->getOabNumero(),
            oabUf: $user->getOabUf(),
            oabStatus: $user->getOabStatus(),
            oabNomeOficial: $user->getOabNomeOficial(),
            oabVerificadaEm: $user->getOabVerificadaEm(),
        );
    }
}
