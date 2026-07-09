<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Caso de Cobrança (SPEC §4): episódio/processo de cobrança relacionado a UM Objeto.
 * Nasce `ativo`; mantém a pessoa cobrada atual (exatamente uma — invariável 7) e o snapshot
 * da regra de honorários aplicada (SPEC §18.2/§18.3 — mudanças futuras na carteira não
 * recalculam casos antigos). O saldo NÃO é coluna: é derivado por `CalculadoraSaldo`
 * (invariável 20). Vínculo com Pasta (judicialização) entra na Etapa 5.
 */
#[ORM\Entity(repositoryClass: CasoCobrancaRepository::class)]
#[ORM\Table(name: 'cobranca_caso')]
#[ORM\Index(name: 'idx_cobranca_caso_tenant_objeto', columns: ['tenant_id', 'objeto_id'])]
#[ORM\HasLifecycleCallbacks]
class CasoCobranca implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: ObjetoCobranca::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?ObjetoCobranca $objeto = null;

    /** Exatamente uma pessoa cobrada por caso (invariável 7); só muda manualmente (SPEC §8). */
    #[ORM\ManyToOne(targetEntity: Pessoa::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Pessoa $pessoaCobradaAtual = null;

    #[ORM\Column(enumType: StatusCaso::class)]
    private StatusCaso $status = StatusCaso::Ativo;

    /** Snapshot da forma de honorários aplicada ao caso (SPEC §18.2). */
    #[ORM\Column(enumType: FormaHonorarios::class)]
    private FormaHonorarios $formaHonorarios = FormaHonorarios::SemPercentual;

    /** Snapshot do percentual aplicado; nulo quando a forma não usa percentual. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $percentualHonorarios = null;

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

    public function estaAtivo(): bool
    {
        return $this->status === StatusCaso::Ativo;
    }

    public function estaEncerrado(): bool
    {
        return $this->status === StatusCaso::Encerrado;
    }

    /** Troca a pessoa cobrada (SPEC §8) — decisão manual; não afeta dívida/pagamentos/acordos. */
    public function definirPessoaCobrada(Pessoa $pessoa): self
    {
        $this->pessoaCobradaAtual = $pessoa;

        return $this;
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

    public function getObjeto(): ?ObjetoCobranca
    {
        return $this->objeto;
    }

    public function setObjeto(ObjetoCobranca $objeto): self
    {
        $this->objeto = $objeto;

        return $this;
    }

    public function getPessoaCobradaAtual(): ?Pessoa
    {
        return $this->pessoaCobradaAtual;
    }

    public function setPessoaCobradaAtual(Pessoa $pessoa): self
    {
        $this->pessoaCobradaAtual = $pessoa;

        return $this;
    }

    public function getStatus(): StatusCaso
    {
        return $this->status;
    }

    public function setStatus(StatusCaso $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getFormaHonorarios(): FormaHonorarios
    {
        return $this->formaHonorarios;
    }

    public function setFormaHonorarios(FormaHonorarios $formaHonorarios): self
    {
        $this->formaHonorarios = $formaHonorarios;

        return $this;
    }

    public function getPercentualHonorarios(): ?string
    {
        return $this->percentualHonorarios;
    }

    public function setPercentualHonorarios(?string $percentualHonorarios): self
    {
        $this->percentualHonorarios = $percentualHonorarios;

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
}
