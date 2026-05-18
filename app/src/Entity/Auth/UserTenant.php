<?php
declare(strict_types=1);

namespace App\Entity\Auth;

use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserTenantRepository::class)]
#[ORM\Table(name: 'user_tenant')]
#[ORM\UniqueConstraint(name: 'uq_user_tenant', columns: ['user_id', 'tenant_id'])]
#[ORM\Index(name: 'idx_user_tenant_user_id', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_tenant_tenant_id', columns: ['tenant_id'])]
class UserTenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $codigoFuncionario = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataAdmissao = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $demitidoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: TenantRole::class, inversedBy: 'userTenants')]
    #[ORM\JoinColumn(nullable: true)]
    private ?TenantRole $tenantRole = null;

    #[ORM\ManyToOne(targetEntity: Cargo::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Cargo $cargo = null;

    #[ORM\ManyToOne(targetEntity: Lotacao::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lotacao $lotacao = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly User $user,

        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Tenant $tenant,

        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }

    public function getTenant(): Tenant { return $this->tenant; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
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

    public function getCodigoFuncionario(): ?string { return $this->codigoFuncionario; }

    public function setCodigoFuncionario(?string $codigoFuncionario): static
    {
        $this->codigoFuncionario = $codigoFuncionario;
        return $this;
    }

    public function getDataAdmissao(): ?\DateTimeImmutable { return $this->dataAdmissao; }

    public function setDataAdmissao(?\DateTimeImmutable $dataAdmissao): static
    {
        $this->dataAdmissao = $dataAdmissao;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getDemitidoEm(): ?\DateTimeImmutable { return $this->demitidoEm; }

    public function isActive(): bool { return $this->isActive; }

    public function demitir(): void
    {
        $this->isActive   = false;
        $this->demitidoEm = new \DateTimeImmutable();
    }

    public function reativar(): void
    {
        $this->isActive   = true;
        $this->demitidoEm = null;
    }
}
