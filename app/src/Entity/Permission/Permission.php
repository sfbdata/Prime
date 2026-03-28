<?php

namespace App\Entity\Permission;

use App\Repository\PermissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PermissionRepository::class)]
#[ORM\Table(name: 'permission')]
class Permission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Código semântico da permissão (ex.: modules.clientes.view, admin.roles.manage).
     */
    #[ORM\Column(length: 100, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    /**
     * Grupo lógico: modules | resources | admin
     */
    #[ORM\Column(name: '"group"', length: 50)]
    private ?string $group = null;

    /**
     * @var Collection<int, \App\Entity\Tenant\TenantRolePermission>
     */
    #[ORM\OneToMany(targetEntity: \App\Entity\Tenant\TenantRolePermission::class, mappedBy: 'permission')]
    private Collection $tenantRolePermissions;

    public function __construct()
    {
        $this->tenantRolePermissions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function setGroup(string $group): static
    {
        $this->group = $group;
        return $this;
    }

    /**
     * @return Collection<int, \App\Entity\Tenant\TenantRolePermission>
     */
    public function getTenantRolePermissions(): Collection
    {
        return $this->tenantRolePermissions;
    }
}
