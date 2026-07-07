<?php

declare(strict_types=1);

namespace App\Djen\Entity;

use App\Djen\Repository\OabMonitoradaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * OAB (advogado) que um escritório deseja monitorar no DJEN. O número é armazenado em dígitos puros;
 * a validação/normalização ocorre no UseCase (reusa App\Auth\Service\ValidadorOab).
 * Unicidade por escritório: um mesmo par número+UF não se repete dentro do tenant.
 */
#[ORM\Entity(repositoryClass: OabMonitoradaRepository::class)]
#[ORM\Table(name: 'oab_monitorada')]
#[ORM\UniqueConstraint(name: 'uniq_oab_monitorada_tenant_numero_uf', columns: ['tenant_id', 'numero', 'uf'])]
#[ORM\HasLifecycleCallbacks]
class OabMonitorada implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** Número da inscrição na OAB, apenas dígitos (ex.: "67228"). */
    #[ORM\Column(length: 20)]
    private string $numero = '';

    /** UF da seccional (ex.: "PR"). */
    #[ORM\Column(length: 2)]
    private string $uf = '';

    /** Rótulo opcional para o escritório identificar a OAB (ex.: "Dra. Fulana"). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apelido = null;

    #[ORM\Column(type: 'boolean')]
    private bool $ativo = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getUf(): string
    {
        return $this->uf;
    }

    public function setUf(string $uf): self
    {
        $this->uf = $uf;

        return $this;
    }

    public function getApelido(): ?string
    {
        return $this->apelido;
    }

    public function setApelido(?string $apelido): self
    {
        $this->apelido = $apelido;

        return $this;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function setAtivo(bool $ativo): self
    {
        $this->ativo = $ativo;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;

        return $this;
    }

    /** Rótulo de exibição: apelido, ou "numero/UF" como fallback. */
    public function getRotulo(): string
    {
        if ($this->apelido !== null && $this->apelido !== '') {
            return $this->apelido;
        }

        return sprintf('%s/%s', $this->numero, $this->uf);
    }
}
