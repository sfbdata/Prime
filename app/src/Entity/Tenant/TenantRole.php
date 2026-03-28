<?php

namespace App\Entity\Tenant;

use App\Repository\TenantRoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TenantRoleRepository::class)]
#[ORM\Table(name: 'tenant_role')]
class TenantRole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'roles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    /**
     * Perfis de sistema (ex.: Administrador do Escritório) não podem ser excluídos.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $isSystem = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, TenantRolePermission>
     */
    #[ORM\OneToMany(targetEntity: TenantRolePermission::class, mappedBy: 'tenantRole', cascade: ['persist', 'remove'])]
    private Collection $tenantRolePermissions;

    /**
     * @var Collection<int, \App\Entity\Auth\User>
     */
    #[ORM\OneToMany(targetEntity: \App\Entity\Auth\User::class, mappedBy: 'tenantRole')]
    private Collection $users;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->tenantRolePermissions = new ArrayCollection();
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): static
    {
        $this->isSystem = $isSystem;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, TenantRolePermission>
     */
    public function getTenantRolePermissions(): Collection
    {
        return $this->tenantRolePermissions;
    }

    /**
     * @return Collection<int, \App\Entity\Auth\User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }
}
