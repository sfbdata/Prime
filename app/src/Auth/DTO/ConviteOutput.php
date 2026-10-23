<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\Invitation;

/**
 * O token só vai para a tela do link público (`/convite/{token}`), e é o que veio na URL, nunca o lido da entidade.
 * Nas listas do convidado logado ele fica nulo e a tela usa o id (achado de auditoria interna F6).
 */
readonly class ConviteOutput
{
    public function __construct(
        public int $id,
        public ?string $token,
        public string $tipo,
        public string $email,
        public ?string $nomeConvidado,
        public ?string $tenantNome,
        public ?string $criadorNome,
        public \DateTimeImmutable $expiresAt,
        public string $status,
    ) {}

    public static function fromInvitation(Invitation $invitation, ?string $tokenDoLink = null): self
    {
        return new self(
            id: (int) $invitation->getId(),
            token: $tokenDoLink,
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
