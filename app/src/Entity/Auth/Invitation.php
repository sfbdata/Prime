<?php

declare(strict_types=1);

namespace App\Entity\Auth;

use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitation')]
#[ORM\UniqueConstraint(name: 'uq_invitation_token', columns: ['token'])]
#[ORM\Index(name: 'idx_invitation_email', columns: ['email'])]
#[ORM\Index(name: 'idx_invitation_status_expires', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'idx_invitation_tenant_status', columns: ['tenant_id', 'status'])]
class Invitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: TenantRole::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TenantRole $tenantRole = null;

    #[ORM\ManyToOne(targetEntity: Cargo::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Cargo $cargo = null;

    #[ORM\ManyToOne(targetEntity: Lotacao::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lotacao $lotacao = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedAsUser = null;

    public function __construct(
        #[ORM\Column(length: 180)]
        private string $email,

        #[ORM\Column(length: 64)]
        private string $token,

        #[ORM\Column(length: 20)]
        private string $type,

        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $expiresAt,

        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }

    public function getToken(): string { return $this->token; }

    public function getType(): string { return $this->type; }

    public function getStatus(): string { return $this->status; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }

    public function getRespondedAt(): ?\DateTimeImmutable { return $this->respondedAt; }

    public function getFullName(): ?string { return $this->fullName; }

    public function setFullName(?string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getTenant(): ?Tenant { return $this->tenant; }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getTenantRole(): ?TenantRole { return $this->tenantRole; }

    public function setTenantRole(?TenantRole $tenantRole): static
    {
        $this->tenantRole = $tenantRole;

        return $this;
    }

    public function getCargo(): ?Cargo { return $this->cargo; }

    public function setCargo(?Cargo $cargo): static
    {
        $this->cargo = $cargo;

        return $this;
    }

    public function getLotacao(): ?Lotacao { return $this->lotacao; }

    public function setLotacao(?Lotacao $lotacao): static
    {
        $this->lotacao = $lotacao;

        return $this;
    }

    public function getCreatedBy(): ?User { return $this->createdBy; }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getAcceptedAsUser(): ?User { return $this->acceptedAsUser; }

    public function setAcceptedAsUser(?User $user): static
    {
        $this->acceptedAsUser = $user;

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function aceitar(User $user): void
    {
        $this->status         = 'accepted';
        $this->acceptedAsUser = $user;
        $this->respondedAt    = new \DateTimeImmutable();
    }

    public function recusar(): void
    {
        $this->status      = 'rejected';
        $this->respondedAt = new \DateTimeImmutable();
    }

    public function revogar(): void
    {
        $this->status = 'revoked';
    }

    public function expirar(): void
    {
        $this->status = 'expired';
    }
}
