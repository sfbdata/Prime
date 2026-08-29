<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O que o cliente combinou pagar por esta pasta: honorário, parcela de
 * honorário, reembolso de custas.
 *
 * Não confundir com a Cobrança: lá o dinheiro é a dívida de um devedor numa
 * carteira, aqui é o que o escritório tem a receber pelo caso. São coisas
 * diferentes, com donos diferentes, e nunca se somam.
 *
 * O ESTADO NÃO É GRAVADO, é derivado de `pagoEm` + `vencimento`. Uma coluna de
 * status abriria a porta para ela divergir da data — que é exatamente o defeito
 * que este repositório já pagou caro em outros módulos.
 */
#[ORM\Entity(repositoryClass: PastaPagamentoRepository::class)]
#[ORM\Table(name: 'pasta_pagamento')]
#[ORM\Index(name: 'idx_pasta_pagamento_pasta_id', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_pasta_pagamento_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_pasta_pagamento_vencimento', columns: ['vencimento'])]
class PastaPagamento implements Auditavel, TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Pasta::class, inversedBy: 'pagamentos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pasta $pasta = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** Quem lançou. Some junto com o usuário; o pagamento não. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $autor = null;

    #[ORM\Column(length: 120)]
    private string $descricao = '';

    /** Decimal em string, como o resto do dinheiro do sistema. Nunca float. */
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $valor = '0.00';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $vencimento;

    /** Preenchido = pago. É esta coluna, e só ela, que define o estado. */
    #[ORM\Column(name: 'pago_em', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $pagoEm = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    public function __construct()
    {
        $this->criadoEm   = new \DateTimeImmutable();
        $this->vencimento = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPasta(): ?Pasta
    {
        return $this->pasta;
    }

    public function setPasta(?Pasta $pasta): self
    {
        $this->pasta = $pasta;

        return $this;
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

    public function getAutor(): ?User
    {
        return $this->autor;
    }

    public function setAutor(?User $autor): self
    {
        $this->autor = $autor;

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

    public function getValor(): string
    {
        return $this->valor;
    }

    public function setValor(string $valor): self
    {
        $this->valor = $valor;

        return $this;
    }

    public function getVencimento(): \DateTimeImmutable
    {
        return $this->vencimento;
    }

    public function setVencimento(\DateTimeImmutable $vencimento): self
    {
        $this->vencimento = $vencimento;

        return $this;
    }

    public function getPagoEm(): ?\DateTimeImmutable
    {
        return $this->pagoEm;
    }

    public function estaPago(): bool
    {
        return $this->pagoEm !== null;
    }

    /**
     * Vencido é pendente com data no passado — nunca um pagamento já quitado,
     * por mais atrasada que a quitação tenha sido.
     */
    public function estaVencido(?\DateTimeImmutable $hoje = null): bool
    {
        if ($this->pagoEm !== null) {
            return false;
        }

        return $this->vencimento < ($hoje ?? new \DateTimeImmutable('today'));
    }

    /**
     * Marca como pago na data informada (hoje, por padrão) ou desfaz a
     * quitação. Uma única porta para os dois sentidos: é o mesmo gesto na tela.
     */
    public function alternarQuitacao(?\DateTimeImmutable $quando = null): self
    {
        $this->pagoEm = $this->pagoEm === null
            ? ($quando ?? new \DateTimeImmutable('today'))
            : null;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
