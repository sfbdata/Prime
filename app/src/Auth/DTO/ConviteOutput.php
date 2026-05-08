<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\Invitation;

readonly class ConviteOutput
{
    public function __construct(
        public string $token,
        public string $tipo,
        public string $email,
        public ?string $nomeConvidado,
        public ?string $tenantNome,
        public ?string $criadorNome,
        public \DateTimeImmutable $expiresAt,
        public string $status,
    ) {}

    public static function fromInvitation(Invitation $invitation): self
    {
        return new self(
            token: $invitation->getToken(),
            tipo: $invitation->getType(),
            email: $invitation->getEmail(),
            nomeConvidado: $invitation->getFullName(),
            tenantNome: $invitation->getTenant()?->getName(),
            criadorNome: $invitation->getCreatedBy()?->getFullName(),
            expiresAt: $invitation->getExpiresAt(),
            status: $invitation->getStatus(),
        );
    }
}
