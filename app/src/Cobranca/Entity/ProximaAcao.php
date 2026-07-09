<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\StatusProximaAcao;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Próxima Ação operacional do Caso de Cobrança (SPEC §14): a "próxima coisa a fazer" definida
 * manualmente pelo gestor (verificar pagamento, contatar, enviar boleto, revisar pessoa, preparar
 * ajuizamento...). O caso possui NO MÁXIMO uma ação `pendente` por vez (invariável de negócio §14,
 * garantida no UseCase via `ProximaAcaoRepository::findAtivaDoCaso`). Ao concluir, o gestor registra
 * o `resultado` e pode definir a próxima. Diferente de alerta (que é derivado de fatos do sistema).
 */
#[ORM\Entity(repositoryClass: ProximaAcaoRepository::class)]
#[ORM\Table(name: 'cobranca_proxima_acao')]
#[ORM\Index(name: 'idx_cobranca_proxima_acao_tenant_caso', columns: ['tenant_id', 'caso_id'])]
#[ORM\HasLifecycleCallbacks]
class ProximaAcao implements TenantAware, Auditavel
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

    #[ORM\Column(length: 255)]
    private string $descricao = '';

    /** Prazo esperado da ação; nulo quando o gestor não define data. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $prazo = null;

    #[ORM\Column(enumType: StatusProximaAcao::class)]
    private StatusProximaAcao $status = StatusProximaAcao::Pendente;

    /** Resultado registrado ao concluir a ação (§14). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resultado = null;

    /** Responsável pela ação (o gestor que a definiu). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $responsavel = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $concluidaEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
    }

    public function estaPendente(): bool
    {
        return $this->status === StatusProximaAcao::Pendente;
    }

    /** Conclui a ação registrando o resultado (§14) — decisão manual do gestor. */
    public function concluir(string $resultado): self
    {
        $this->status = StatusProximaAcao::Concluida;
        $this->resultado = $resultado;
        $this->concluidaEm = new \DateTimeImmutable();

        return $this;
    }

    /** Está atrasada: pendente e com prazo já vencido (base do alerta de ação atrasada, §14). */
    public function estaAtrasada(\DateTimeImmutable $hoje): bool
    {
        return $this->estaPendente() && $this->prazo !== null && $this->prazo < $hoje;
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

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = $descricao;

        return $this;
    }

    public function getPrazo(): ?\DateTimeImmutable
    {
        return $this->prazo;
    }

    public function setPrazo(?\DateTimeImmutable $prazo): self
    {
        $this->prazo = $prazo;

        return $this;
    }

    public function getStatus(): StatusProximaAcao
    {
        return $this->status;
    }

    public function setStatus(StatusProximaAcao $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getResultado(): ?string
    {
        return $this->resultado;
    }

    public function setResultado(?string $resultado): self
    {
        $this->resultado = $resultado;

        return $this;
    }

    public function getResponsavel(): ?User
    {
        return $this->responsavel;
    }

    public function setResponsavel(?User $responsavel): self
    {
        $this->responsavel = $responsavel;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getConcluidaEm(): ?\DateTimeImmutable
    {
        return $this->concluidaEm;
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
