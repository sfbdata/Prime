<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\HomeOfficeConfigRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Regra de home office de um colaborador em um escritório (tenant): em quais dias ele pode
 * bater ponto fora do raio das sedes (bypass de geofencing). Uma config por (user, tenant).
 * Cobre "100%", "alguns dias", "por período" e datas avulsas num só modelo.
 */
#[ORM\Entity(repositoryClass: HomeOfficeConfigRepository::class)]
#[ORM\Table(name: 'home_office_config')]
#[ORM\UniqueConstraint(name: 'uniq_home_office_user_tenant', columns: ['user_id', 'tenant_id'])]
class HomeOfficeConfig implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** @var int[] Dias da semana recorrentes de home office (1=Segunda ... 7=Domingo). */
    #[ORM\Column(type: 'json')]
    private array $diasSemana = [];

    /** @var string[] Datas avulsas de home office no formato 'Y-m-d'. */
    #[ORM\Column(type: 'json')]
    private array $datasAvulsas = [];

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $vigenciaInicio = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $vigenciaFim = null;

    #[ORM\Column]
    private bool $ativo = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    /** @return int[] */
    public function getDiasSemana(): array
    {
        return $this->diasSemana;
    }

    /** @param int[] $diasSemana */
    public function setDiasSemana(array $diasSemana): static
    {
        $this->diasSemana = array_values($diasSemana);

        return $this;
    }

    /** @return string[] */
    public function getDatasAvulsas(): array
    {
        return $this->datasAvulsas;
    }

    /** @param string[] $datasAvulsas */
    public function setDatasAvulsas(array $datasAvulsas): static
    {
        $this->datasAvulsas = array_values($datasAvulsas);

        return $this;
    }

    public function getVigenciaInicio(): ?\DateTimeImmutable
    {
        return $this->vigenciaInicio;
    }

    public function setVigenciaInicio(?\DateTimeImmutable $vigenciaInicio): static
    {
        $this->vigenciaInicio = $vigenciaInicio;

        return $this;
    }

    public function getVigenciaFim(): ?\DateTimeImmutable
    {
        return $this->vigenciaFim;
    }

    public function setVigenciaFim(?\DateTimeImmutable $vigenciaFim): static
    {
        $this->vigenciaFim = $vigenciaFim;

        return $this;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function setAtivo(bool $ativo): static
    {
        $this->ativo = $ativo;

        return $this;
    }
}
