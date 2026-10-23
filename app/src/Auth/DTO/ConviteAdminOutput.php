<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\Invitation;

/** Para quem gerencia convites: leva o id, nunca o token, que só existe no link do e-mail (achado de auditoria interna F4). */
readonly class ConviteAdminOutput
{
    public function __construct(
        public int $id,
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
            id: (int) $invitation->getId(),
            tipo: $invitation->getType(),
            email: $invitation->getEmail(),
            nomeConvidado: $invitation->getFullName(),
            tenantNome: $invitation->getTenant()?->getName(),
            // Nome EXIBIDO (TenantRole::getNomeExibido): o perfil de sistema aparece como "Usuário Primário".
            roleName: $invitation->getTenantRole()?->getNomeExibido(),
            status: $invitation->getStatus(),
            createdAt: $invitation->getCreatedAt(),
            expiresAt: $invitation->getExpiresAt(),
            reenvioCount: $invitation->getReenvioCount(),
            podeReenviar: $invitation->podeReenviar(),
        );
    }
}
