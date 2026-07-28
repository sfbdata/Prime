<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\TipoTelefone;
use App\Cobranca\Repository\PessoaTelefoneRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um telefone da Pessoa cobrada, na linha do tempo (spec de qualificação §4). A pessoa pode ter
 * VÁRIOS telefones — "adicionar" nunca apaga o anterior; exatamente um é o `atual` por vez. A
 * invariante "exatamente um atual" é garantida pelos UseCases (AdicionarTelefonePessoa/
 * MarcarTelefoneAtual), não aqui. Exibição em ordem `criadoEm ASC` (linha do tempo).
 */
#[ORM\Entity(repositoryClass: PessoaTelefoneRepository::class)]
#[ORM\Table(name: 'cobranca_pessoa_telefone')]
#[ORM\HasLifecycleCallbacks]
class PessoaTelefone implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Pessoa::class, inversedBy: 'telefones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Pessoa $pessoa = null;

    #[ORM\Column(length: 20)]
    private string $numero = '';

    /**
     * WhatsApp ou telefone comum (2026-07-28). NULLABLE: o dado anterior a esta frente não tem tipo e
     * ninguém o declarou — ver a justificativa em {@see TipoTelefone}.
     */
    #[ORM\Column(length: 20, nullable: true, enumType: TipoTelefone::class)]
    private ?TipoTelefone $tipo = null;

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

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getTipo(): ?TipoTelefone
    {
        return $this->tipo;
    }

    public function setTipo(?TipoTelefone $tipo): self
    {
        $this->tipo = $tipo;

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
