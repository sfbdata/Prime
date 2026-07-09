<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Repository\ObrigacaoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Obrigação (SPEC §10): um valor devido dentro de um Caso de Cobrança (competência, parcela,
 * mensalidade, taxa, aluguel...). Preserva SEMPRE o valor e o vencimento ORIGINAIS (invariável 20);
 * encargos reconhecidos manualmente (juros/multa/correção) entram à parte, sem recalcular nada
 * automaticamente e sem apagar o original (SPEC §10). Valores em CENTAVOS inteiros.
 */
#[ORM\Entity(repositoryClass: ObrigacaoRepository::class)]
#[ORM\Table(name: 'cobranca_obrigacao')]
#[ORM\Index(name: 'idx_cobranca_obrigacao_tenant_caso', columns: ['tenant_id', 'caso_id'])]
#[ORM\HasLifecycleCallbacks]
class Obrigacao implements TenantAware, Auditavel
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

    /** Valor original em CENTAVOS (invariável 20 — nunca apagado). */
    #[ORM\Column(type: 'integer')]
    private int $valorOriginal = 0;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $vencimentoOriginal;

    /** Encargos reconhecidos manualmente pelo gestor, em CENTAVOS (SPEC §10); default 0. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $encargosReconhecidos = 0;

    /** Referência da fonte externa (importação), para deduplicação intra-tenant. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referenciaExterna = null;

    /** Acordo que GEROU esta obrigação como parcela (SPEC §12); nulo se não é parcela de acordo. */
    #[ORM\ManyToOne(targetEntity: Acordo::class, inversedBy: 'parcelas')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Acordo $acordoOrigem = null;

    /** Acordo que SUBSTITUIU esta obrigação (SPEC §12, invariável 14 — nunca apagada); nulo se ativa. */
    #[ORM\ManyToOne(targetEntity: Acordo::class, inversedBy: 'obrigacoesSubstituidas')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Acordo $acordoSubstituto = null;

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
        $this->vencimentoOriginal = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /** Valor exigível da obrigação em centavos: original + encargos reconhecidos (SPEC §10). */
    public function valorExigivel(): int
    {
        return $this->valorOriginal + $this->encargosReconhecidos;
    }

    /** Reconhece encargos atualizados (juros/multa/correção) em centavos — não toca o original. */
    public function reconhecerEncargos(int $encargosEmCentavos): self
    {
        $this->encargosReconhecidos = $encargosEmCentavos;

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

    public function getDescricao(): string
    {
        return $this->descricao;
    }

    public function setDescricao(string $descricao): self
    {
        $this->descricao = $descricao;

        return $this;
    }

    public function getValorOriginal(): int
    {
        return $this->valorOriginal;
    }

    public function setValorOriginal(int $valorOriginal): self
    {
        $this->valorOriginal = $valorOriginal;

        return $this;
    }

    public function getVencimentoOriginal(): \DateTimeImmutable
    {
        return $this->vencimentoOriginal;
    }

    public function setVencimentoOriginal(\DateTimeImmutable $vencimentoOriginal): self
    {
        $this->vencimentoOriginal = $vencimentoOriginal;

        return $this;
    }

    public function getEncargosReconhecidos(): int
    {
        return $this->encargosReconhecidos;
    }

    public function setEncargosReconhecidos(int $encargosReconhecidos): self
    {
        $this->encargosReconhecidos = $encargosReconhecidos;

        return $this;
    }

    public function getReferenciaExterna(): ?string
    {
        return $this->referenciaExterna;
    }

    public function setReferenciaExterna(?string $referenciaExterna): self
    {
        $this->referenciaExterna = $referenciaExterna;

        return $this;
    }

    public function getAcordoOrigem(): ?Acordo
    {
        return $this->acordoOrigem;
    }

    public function setAcordoOrigem(?Acordo $acordoOrigem): self
    {
        $this->acordoOrigem = $acordoOrigem;

        return $this;
    }

    public function getAcordoSubstituto(): ?Acordo
    {
        return $this->acordoSubstituto;
    }

    public function setAcordoSubstituto(?Acordo $acordoSubstituto): self
    {
        $this->acordoSubstituto = $acordoSubstituto;

        return $this;
    }

    /** A obrigação foi substituída por um acordo (marcada, nunca apagada — invariável 14)? */
    public function foiSubstituida(): bool
    {
        return $this->acordoSubstituto !== null;
    }

    /** A obrigação é uma parcela gerada por um acordo? */
    public function ehParcela(): bool
    {
        return $this->acordoOrigem !== null;
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
