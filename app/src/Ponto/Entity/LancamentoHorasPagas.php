<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ajuste manual do banco de horas de um colaborador, preso a uma COMPETÊNCIA (mês/ano), não a um dia.
 *
 * `minutos` carrega o sinal: negativo desconta do banco (horas pagas em dinheiro na folha salarial),
 * positivo acrescenta (bonificação). Nunca zero. Vários lançamentos na mesma competência somam.
 *
 * Implementa Auditavel: criação, edição e exclusão ficam registradas no audit_log. É a única prova
 * que sobra, já que editar/excluir apaga o registro do produto — e isto altera verba trabalhista.
 */
#[ORM\Entity(repositoryClass: LancamentoHorasPagasRepository::class)]
#[ORM\Table(name: 'ponto_lancamento_horas_pagas')]
#[ORM\Index(fields: ['tenant', 'user', 'ano', 'mes'], name: 'IDX_HORAS_PAGAS_COMPETENCIA')]
class LancamentoHorasPagas implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(type: 'smallint')]
    private int $ano = 0;

    #[ORM\Column(type: 'smallint')]
    private int $mes = 0;

    #[ORM\Column]
    private int $minutos = 0;

    #[ORM\Column(type: 'text')]
    private string $motivo = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $criadoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $criadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $atualizadoPor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getAno(): int
    {
        return $this->ano;
    }

    public function setAno(int $ano): self
    {
        $this->ano = $ano;

        return $this;
    }

    public function getMes(): int
    {
        return $this->mes;
    }

    public function setMes(int $mes): self
    {
        $this->mes = $mes;

        return $this;
    }

    public function getMinutos(): int
    {
        return $this->minutos;
    }

    public function setMinutos(int $minutos): self
    {
        $this->minutos = $minutos;

        return $this;
    }

    public function getMotivo(): string
    {
        return $this->motivo;
    }

    public function setMotivo(string $motivo): self
    {
        $this->motivo = $motivo;

        return $this;
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

    public function getCriadoEm(): ?\DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function setCriadoEm(?\DateTimeImmutable $criadoEm): self
    {
        $this->criadoEm = $criadoEm;

        return $this;
    }

    public function getAtualizadoPor(): ?User
    {
        return $this->atualizadoPor;
    }

    public function setAtualizadoPor(?User $atualizadoPor): self
    {
        $this->atualizadoPor = $atualizadoPor;

        return $this;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function setAtualizadoEm(?\DateTimeImmutable $atualizadoEm): self
    {
        $this->atualizadoEm = $atualizadoEm;

        return $this;
    }
}
