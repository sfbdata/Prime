<?php

namespace App\Entity\Permission;

use App\Entity\Auth\User;
use App\Repository\ResourceAccessRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Controle de acesso granular por item de domínio.
 *
 * Armazena quais ações (view, edit, delete) um usuário específico
 * pode executar sobre um item específico (resourceType + resourceId).
 *
 * Hierarquia de verificação no PermissionChecker:
 *  1. ROLE_SUPER_ADMIN → acesso total.
 *  2. ResourceAccess por item → acesso concedido se registro existe.
 *  3. Permissão de tipo resources.<type>.<action> no TenantRole → fallback de perfil.
 */
#[ORM\Entity(repositoryClass: ResourceAccessRepository::class)]
#[ORM\Table(name: 'resource_access')]
#[ORM\UniqueConstraint(name: 'uniq_resource_access_user_resource', columns: ['user_id', 'resource_type', 'resource_id'])]
class ResourceAccess
{
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

    /**
     * Tipo do recurso: "cliente", "pasta" ou "processo".
     */
    #[ORM\Column(length: 50)]
    private ?string $resourceType = null;

    /**
     * ID do item de domínio (PK da entidade correspondente).
     */
    #[ORM\Column]
    private ?int $resourceId = null;

    /**
     * Ação "view" permitida para este item.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $canView = false;

    /**
     * Ação "edit" permitida para este item.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $canEdit = false;

    /**
     * Ação "delete" permitida para este item.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $canDelete = false;

    #[ORM\Column]
    private \DateTimeImmutable $grantedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $grantedBy = null;

    public function __construct()
    {
        $this->grantedAt = new \DateTimeImmutable();
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

    public function isCanView(): bool
    {
        return $this->canView;
    }

    public function setCanView(bool $canView): static
    {
        $this->canView = $canView;
        return $this;
    }

    public function isCanEdit(): bool
    {
        return $this->canEdit;
    }

    public function setCanEdit(bool $canEdit): static
    {
        $this->canEdit = $canEdit;
        return $this;
    }

    public function isCanDelete(): bool
    {
        return $this->canDelete;
    }

    public function setCanDelete(bool $canDelete): static
    {
        $this->canDelete = $canDelete;
        return $this;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getGrantedBy(): ?User
    {
        return $this->grantedBy;
    }

    public function setGrantedBy(?User $grantedBy): static
    {
        $this->grantedBy = $grantedBy;
        return $this;
    }

    /**
     * Verifica se o registro concede a ação solicitada.
     */
    public function allows(string $action): bool
    {
        return match ($action) {
            self::ACTION_VIEW   => $this->canView,
            self::ACTION_EDIT   => $this->canEdit,
            self::ACTION_DELETE => $this->canDelete,
            default             => false,
        };
    }
}
