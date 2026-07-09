<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\StatusRevisao;
use App\Cobranca\Repository\RevisaoPessoaCobradaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pendência de Revisão de Pessoa Cobrada (SPEC §8): uma mudança relevante de vínculo com o objeto
 * (ex.: a pessoa cobrada deixou de ser proprietária e há um novo proprietário) pode gerar esta
 * pendência para o gestor revisar. A cobrança continua na pessoa anterior até decisão manual
 * (invariáveis 9/10/28). Enquanto `pendente`, alimenta o alerta de revisão; depois de `resolvida`
 * (manter ou substituir a pessoa cobrada), o mesmo evento NÃO deve continuar gerando alerta (§8).
 */
#[ORM\Entity(repositoryClass: RevisaoPessoaCobradaRepository::class)]
#[ORM\Table(name: 'cobranca_revisao_pessoa_cobrada')]
#[ORM\Index(name: 'idx_cobranca_revisao_tenant_caso', columns: ['tenant_id', 'caso_id'])]
#[ORM\HasLifecycleCallbacks]
class RevisaoPessoaCobrada implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: CasoCobranca::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?CasoCobranca $caso = null;

    /** Motivo que originou a pendência de revisão (o que mudou no vínculo). */
    #[ORM\Column(length: 255)]
    private string $motivo = '';

    #[ORM\Column(enumType: StatusRevisao::class)]
    private StatusRevisao $status = StatusRevisao::Pendente;

    /** Decisão registrada ao resolver (manter ou substituir a pessoa cobrada). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resolucao = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvidaEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvidaPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function estaPendente(): bool
    {
        return $this->status === StatusRevisao::Pendente;
    }

    /** Resolve a pendência registrando a decisão (§8) — para de gerar alerta depois disso. */
    public function resolver(string $resolucao, ?User $resolvidaPor): self
    {
        $this->status = StatusRevisao::Resolvida;
        $this->resolucao = $resolucao;
        $this->resolvidaPor = $resolvidaPor;
        $this->resolvidaEm = new \DateTimeImmutable();

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

    public function getCaso(): ?CasoCobranca
    {
        return $this->caso;
    }

    public function setCaso(CasoCobranca $caso): self
    {
        $this->caso = $caso;

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

    public function getStatus(): StatusRevisao
    {
        return $this->status;
    }

    public function setStatus(StatusRevisao $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getResolucao(): ?string
    {
        return $this->resolucao;
    }

    public function setResolucao(?string $resolucao): self
    {
        $this->resolucao = $resolucao;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getResolvidaEm(): ?\DateTimeImmutable
    {
        return $this->resolvidaEm;
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

    public function getResolvidaPor(): ?User
    {
        return $this->resolvidaPor;
    }

    public function setResolvidaPor(?User $resolvidaPor): self
    {
        $this->resolvidaPor = $resolvidaPor;

        return $this;
    }
}
