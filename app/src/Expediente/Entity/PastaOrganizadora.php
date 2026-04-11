<?php

namespace App\Expediente\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\PastaOrganizadoraRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PastaOrganizadoraRepository::class)]
#[ORM\HasLifecycleCallbacks]
class PastaOrganizadora
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $nome;

    #[ORM\Column(type: 'integer')]
    private int $ordem = 0;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'subpastas')]
    #[ORM\JoinColumn(name: 'pai_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?self $pai = null;

    #[ORM\OneToMany(mappedBy: 'pai', targetEntity: self::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordem' => 'ASC', 'nome' => 'ASC'])]
    private Collection $subpastas;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $criadoPor = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $cor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $criadoAt = null;

    public function __construct(string $nome, Tenant $tenant, User $criadoPor, ?self $pai = null)
    {
        $this->nome      = $nome;
        $this->tenant    = $tenant;
        $this->criadoPor = $criadoPor;
        $this->pai       = $pai;
        $this->subpastas = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function aoPeristir(): void
    {
        $this->criadoAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = $nome;

        return $this;
    }

    public function getOrdem(): int
    {
        return $this->ordem;
    }

    public function setOrdem(int $ordem): static
    {
        $this->ordem = $ordem;

        return $this;
    }

    public function getPai(): ?self
    {
        return $this->pai;
    }

    public function setPai(?self $pai): static
    {
        $this->pai = $pai;

        return $this;
    }

    public function getSubpastas(): Collection
    {
        return $this->subpastas;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getCriadoAt(): ?\DateTimeImmutable
    {
        return $this->criadoAt;
    }

    public function getCor(): ?string
    {
        return $this->cor;
    }

    public function setCor(?string $cor): static
    {
        $this->cor = $cor;

        return $this;
    }

    public function isRaiz(): bool
    {
        return $this->pai === null;
    }
}
