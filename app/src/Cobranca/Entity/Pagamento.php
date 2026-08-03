<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Repository\PagamentoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pagamento monetário de um Caso de Cobrança, confirmado MANUALMENTE (SPEC §11). Valores em
 * CENTAVOS inteiros. A composição separa a dívida do credor dos honorários do escritório
 * (invariável 18): `valorDivida`+`valorEncargos` abatem o saldo do credor (e somam Σ das
 * alocações); `valorHonorarios` é a parcela do escritório — só ≠0 quando a forma é
 * `acrescido_divida` (o devedor paga dívida+honorários juntos e o pagamento é rateado, §18).
 * O que abate cada obrigação vai nas `AlocacaoPagamento` (o pagamento não atravessa casos —
 * invariável 12). CORRIGIR não estorna: reescreve a composição (`motivoCorrecao`), rastreável pela
 * auditoria técnica (SPEC §22); para desfazer o lançamento existe EXCLUIR o recebimento
 * (`ExcluirPagamentoUseCase`), que apaga o pagamento e devolve o valor ao saldo. Saldo é sempre
 * derivado (invariável 20), nunca coluna.
 */
#[ORM\Entity(repositoryClass: PagamentoRepository::class)]
#[ORM\Table(name: 'cobranca_pagamento')]
#[ORM\Index(name: 'idx_cobranca_pagamento_tenant_caso', columns: ['tenant_id', 'caso_id'])]
#[ORM\HasLifecycleCallbacks]
class Pagamento implements TenantAware, Auditavel
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

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $data;

    /** Parcela do pagamento que abate o principal da dívida do credor, em CENTAVOS. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $valorDivida = 0;

    /** Parcela que abate encargos reconhecidos (juros/multa/correção), em CENTAVOS — registrada à parte (SPEC §11). */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $valorEncargos = 0;

    /** Honorários realizados atribuídos ao pagamento, em CENTAVOS; ≠0 só no `acrescido_divida` (SPEC §18). */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $valorHonorarios = 0;

    /** Motivo da correção (SPEC §22); nulo enquanto o pagamento não foi corrigido — corrigir reescreve, não estorna. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motivoCorrecao = null;

    /**
     * Alocações deste pagamento entre as obrigações do caso (invariável 12). Σ dos valores das
     * alocações = `valorRecuperadoDivida()`.
     *
     * @var Collection<int, AlocacaoPagamento>
     */
    #[ORM\OneToMany(
        mappedBy: 'pagamento',
        targetEntity: AlocacaoPagamento::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $alocacoes;

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
        $this->data = new \DateTimeImmutable();
        $this->alocacoes = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /** O que abate o saldo do credor, em centavos (principal + encargos) — igual a Σ das alocações. */
    public function valorRecuperadoDivida(): int
    {
        return $this->valorDivida + $this->valorEncargos;
    }

    /** Bruto pago pelo devedor, em centavos (dívida do credor + honorários) — relevante no `acrescido_divida`. */
    public function valorTotalRecebido(): int
    {
        return $this->valorRecuperadoDivida() + $this->valorHonorarios;
    }

    /** Adiciona uma alocação e mantém o lado inverso consistente. */
    public function adicionarAlocacao(AlocacaoPagamento $alocacao): self
    {
        if ($this->alocacoes->contains($alocacao) === false) {
            $this->alocacoes->add($alocacao);
            $alocacao->setPagamento($this);
        }

        return $this;
    }

    /** Remove TODAS as alocações (usado ao corrigir o pagamento — orphanRemoval apaga as antigas). */
    public function limparAlocacoes(): self
    {
        foreach ($this->alocacoes->toArray() as $alocacao) {
            $this->alocacoes->removeElement($alocacao);
        }

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

    public function getData(): \DateTimeImmutable
    {
        return $this->data;
    }

    public function setData(\DateTimeImmutable $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getValorDivida(): int
    {
        return $this->valorDivida;
    }

    public function setValorDivida(int $valorDivida): self
    {
        $this->valorDivida = $valorDivida;

        return $this;
    }

    public function getValorEncargos(): int
    {
        return $this->valorEncargos;
    }

    public function setValorEncargos(int $valorEncargos): self
    {
        $this->valorEncargos = $valorEncargos;

        return $this;
    }

    public function getValorHonorarios(): int
    {
        return $this->valorHonorarios;
    }

    public function setValorHonorarios(int $valorHonorarios): self
    {
        $this->valorHonorarios = $valorHonorarios;

        return $this;
    }

    public function getMotivoCorrecao(): ?string
    {
        return $this->motivoCorrecao;
    }

    public function setMotivoCorrecao(?string $motivoCorrecao): self
    {
        $this->motivoCorrecao = $motivoCorrecao;

        return $this;
    }

    /**
     * @return Collection<int, AlocacaoPagamento>
     */
    public function getAlocacoes(): Collection
    {
        return $this->alocacoes;
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
