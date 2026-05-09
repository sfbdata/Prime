<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\Invitation;

readonly class ConviteAdminOutput
{
    public function __construct(
        public string $token,
        public string $tipo,
        public string $email,
        public ?string $nomeConvidado,
        public ?string $tenantNome,
        public ?string $roleName,
        public string $status,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public int $reenvioCount,
        public bool $podeReenviar,
    ) {}

    public static function fromInvitation(Invitation $invitation): self
    {
        return new self(
            token: $invitation->getToken(),
            tipo: $invitation->getType(),
            email: $invitation->getEmail(),
            nomeConvidado: $invitation->getFullName(),
            tenantNome: $invitation->getTenant()?->getName(),
            roleName: $invitation->getTenantRole()?->getName(),
            status: $invitation->getStatus(),
            createdAt: $invitation->getCreatedAt(),
            expiresAt: $invitation->getExpiresAt(),
            reenvioCount: $invitation->getReenvioCount(),
            podeReenviar: $invitation->podeReenviar(),
        );
    }
}
