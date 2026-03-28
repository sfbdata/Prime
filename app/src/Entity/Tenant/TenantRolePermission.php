<?php

namespace App\Entity\Tenant;

use App\Entity\Permission\Permission;
use App\Repository\TenantRolePermissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TenantRolePermissionRepository::class)]
#[ORM\Table(name: 'tenant_role_permission')]
#[ORM\UniqueConstraint(name: 'uq_tenant_role_permission', columns: ['tenant_role_id', 'permission_id'])]
class TenantRolePermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tenantRolePermissions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TenantRole $tenantRole = null;

    #[ORM\ManyToOne(inversedBy: 'tenantRolePermissions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Permission $permission = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $grantedAt = null;

    public function __construct()
    {
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantRole(): ?TenantRole
    {
        return $this->tenantRole;
    }

    public function setTenantRole(?TenantRole $tenantRole): static
    {
        $this->tenantRole = $tenantRole;
        return $this;
    }

    public function getPermission(): ?Permission
    {
        return $this->permission;
    }

    public function setPermission(?Permission $permission): static
    {
        $this->permission = $permission;
        return $this;
    }

    public function getGrantedAt(): ?\DateTimeImmutable
    {
        return $this->grantedAt;
    }
}
