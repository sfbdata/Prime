<?php

namespace App\Entity\Permission;

use App\Entity\Auth\User;
use App\Repository\AccessRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Solicitação de acesso a um item de domínio específico.
 *
 * Criada automaticamente quando canAccessResource retorna false no show.
 * Status: pending → approved | denied (aprovação pelo admin no Dia 13).
 */
#[ORM\Entity(repositoryClass: AccessRequestRepository::class)]
#[ORM\Table(name: 'access_request')]
#[ORM\UniqueConstraint(
    name: 'uniq_access_request_pending',
    columns: ['user_id', 'resource_type', 'resource_id', 'action', 'status'],
)]
class AccessRequest
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED   = 'denied';

    public const RESOURCE_CLIENTE  = 'cliente';
    public const RESOURCE_PASTA    = 'pasta';
    public const RESOURCE_PROCESSO = 'processo';

    public const ACTION_VIEW   = 'view';
    public const ACTION_EDIT   = 'edit';
    public const ACTION_DELETE = 'delete';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Tipo do recurso: "cliente", "pasta" ou "processo". */
    #[ORM\Column(length: 50)]
    private ?string $resourceType = null;

    /** ID do item de domínio (PK da entidade correspondente). */
    #[ORM\Column]
    private ?int $resourceId = null;

    /** Ação solicitada: "view", "edit" ou "delete". */
    #[ORM\Column(length: 20)]
    private ?string $action = null;

    /** Status atual: "pending", "approved" ou "denied". */
    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    /** Preenchido quando o admin toma uma decisão (Dia 13). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Admin que tomou a decisão (Dia 13). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    public function setResourceType(string $resourceType): static
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    public function getResourceId(): ?int
    {
        return $this->resourceId;
    }

    public function setResourceId(int $resourceId): static
    {
        $this->resourceId = $resourceId;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;
        return $this;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): static
    {
        $this->decidedBy = $decidedBy;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
