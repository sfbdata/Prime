<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quanto de um Pagamento abateu uma Obrigação específica (SPEC §11). É o elo que garante que o
 * pagamento NÃO atravessa casos (invariável 12): toda obrigação alocada é do mesmo Caso do
 * pagamento — validado no UseCase. Valor em CENTAVOS. Entidade de associação, mas ainda assim
 * `TenantAware` (defesa em profundidade, PLAN §6) e `Auditavel` (toca dinheiro). A soma das
 * alocações de um pagamento = `Pagamento::valorRecuperadoDivida()`.
 */
#[ORM\Entity(repositoryClass: AlocacaoPagamentoRepository::class)]
#[ORM\Table(name: 'cobranca_alocacao_pagamento')]
#[ORM\Index(name: 'idx_cobranca_alocacao_tenant_pagamento', columns: ['tenant_id', 'pagamento_id'])]
#[ORM\Index(name: 'idx_cobranca_alocacao_obrigacao', columns: ['obrigacao_id'])]
class AlocacaoPagamento implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Pagamento::class, inversedBy: 'alocacoes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Pagamento $pagamento = null;

    #[ORM\ManyToOne(targetEntity: Obrigacao::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Obrigacao $obrigacao = null;

    /** Valor deste pagamento aplicado a esta obrigação, em CENTAVOS (principal + encargos). */
    #[ORM\Column(type: 'integer')]
    private int $valor = 0;

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

    public function getPagamento(): ?Pagamento
    {
        return $this->pagamento;
    }

    public function setPagamento(Pagamento $pagamento): self
    {
        $this->pagamento = $pagamento;

        return $this;
    }

    public function getObrigacao(): ?Obrigacao
    {
        return $this->obrigacao;
    }

    public function setObrigacao(Obrigacao $obrigacao): self
    {
        $this->obrigacao = $obrigacao;

        return $this;
    }

    public function getValor(): int
    {
        return $this->valor;
    }

    public function setValor(int $valor): self
    {
        $this->valor = $valor;

        return $this;
    }
}
