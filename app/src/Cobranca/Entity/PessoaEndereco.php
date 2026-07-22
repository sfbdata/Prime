<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Repository\PessoaEnderecoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um endereço da Pessoa cobrada, na linha do tempo (spec de qualificação §4). A pessoa pode ter
 * VÁRIOS endereços — "adicionar" nunca apaga o anterior; exatamente um é o `atual` por vez. A
 * invariante "exatamente um atual" é garantida pelos UseCases (Adicionar/MarcarEnderecoAtual),
 * não aqui. Exibição em ordem `criadoEm ASC` (linha do tempo).
 */
#[ORM\Entity(repositoryClass: PessoaEnderecoRepository::class)]
#[ORM\Table(name: 'cobranca_pessoa_endereco')]
#[ORM\HasLifecycleCallbacks]
class PessoaEndereco implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Pessoa::class, inversedBy: 'enderecos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pessoa $pessoa = null;

    #[ORM\Column(length: 255)]
    private string $logradouro = '';

    #[ORM\Column(length: 20)]
    private string $numero = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $complemento = null;

    #[ORM\Column(length: 120)]
    private string $bairro = '';

    #[ORM\Column(length: 120)]
    private string $cidade = '';

    #[ORM\Column(length: 2)]
    private string $uf = '';

    #[ORM\Column(length: 9)]
    private string $cep = '';

    #[ORM\Column(type: 'boolean')]
    private bool $atual = false;

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

    public function getPessoa(): ?Pessoa
    {
        return $this->pessoa;
    }

    public function setPessoa(Pessoa $pessoa): self
    {
        $this->pessoa = $pessoa;

        return $this;
    }

    public function getLogradouro(): string
    {
        return $this->logradouro;
    }

    public function setLogradouro(string $logradouro): self
    {
        $this->logradouro = $logradouro;

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

    public function getComplemento(): ?string
    {
        return $this->complemento;
    }

    public function setComplemento(?string $complemento): self
    {
        $this->complemento = $complemento;

        return $this;
    }

    public function getBairro(): string
    {
        return $this->bairro;
    }

    public function setBairro(string $bairro): self
    {
        $this->bairro = $bairro;

        return $this;
    }

    public function getCidade(): string
    {
        return $this->cidade;
    }

    public function setCidade(string $cidade): self
    {
        $this->cidade = $cidade;

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

    public function getCep(): string
    {
        return $this->cep;
    }

    public function setCep(string $cep): self
    {
        $this->cep = $cep;

        return $this;
    }

    public function isAtual(): bool
    {
        return $this->atual;
    }

    public function setAtual(bool $atual): self
    {
        $this->atual = $atual;

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
