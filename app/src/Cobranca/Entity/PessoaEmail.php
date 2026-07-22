<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Repository\PessoaEmailRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um e-mail da Pessoa cobrada, na linha do tempo (spec de qualificação §4). A pessoa pode ter
 * VÁRIOS e-mails — "adicionar" nunca apaga o anterior; exatamente um é o `atual` por vez. A
 * invariante "exatamente um atual" é garantida pelos UseCases (AdicionarEmailPessoa/
 * MarcarEmailAtual), não aqui. Exibição em ordem `criadoEm ASC` (linha do tempo).
 */
#[ORM\Entity(repositoryClass: PessoaEmailRepository::class)]
#[ORM\Table(name: 'cobranca_pessoa_email')]
#[ORM\HasLifecycleCallbacks]
class PessoaEmail implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Pessoa::class, inversedBy: 'emails')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pessoa $pessoa = null;

    #[ORM\Column(length: 255)]
    private string $email = '';

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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

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
