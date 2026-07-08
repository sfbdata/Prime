<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Objeto de Cobrança (SPEC §4): o elemento ao qual a dívida está vinculada (unidade, sala,
 * veículo, imóvel, contrato, matrícula...). Pertence a uma Carteira; pode possuir vários casos
 * ao longo do tempo. O conceito é genérico — a UI usa o rótulo da carteira.
 */
#[ORM\Entity(repositoryClass: ObjetoCobrancaRepository::class)]
#[ORM\Table(name: 'cobranca_objeto')]
#[ORM\HasLifecycleCallbacks]
class ObjetoCobranca implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Carteira::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Carteira $carteira = null;

    /** Identificação do objeto no contexto da carteira (ex.: "Apto 402", placa, matrícula). */
    #[ORM\Column(length: 255)]
    private string $identificacao = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricao = null;

    /** Referência da fonte externa (importação), para deduplicação intra-tenant em reimportações. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referenciaExterna = null;

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

    public function getCarteira(): ?Carteira
    {
        return $this->carteira;
    }

    public function setCarteira(Carteira $carteira): self
    {
        $this->carteira = $carteira;

        return $this;
    }

    public function getIdentificacao(): string
    {
        return $this->identificacao;
    }

    public function setIdentificacao(string $identificacao): self
    {
        $this->identificacao = $identificacao;

        return $this;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function setDescricao(?string $descricao): self
    {
        $this->descricao = $descricao;

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
