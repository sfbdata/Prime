<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\Repository\KanbanBoardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanBoardRepository::class)]
#[ORM\Table(name: 'kanban_board')]
#[ORM\HasLifecycleCallbacks]
class KanbanBoard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $nome;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricao = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $cor = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $criadoPor = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'kanban_board_participante')]
    private Collection $participantes;

    #[ORM\OneToMany(mappedBy: 'board', targetEntity: KanbanColuna::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['posicao' => 'ASC'])]
    private Collection $colunas;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $criadoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(string $nome, Tenant $tenant, User $criadoPor)
    {
        $this->nome         = $nome;
        $this->tenant       = $tenant;
        $this->criadoPor    = $criadoPor;
        $this->participantes = new ArrayCollection();
        $this->colunas      = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function aoPersistir(): void
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

    public function getNome(): string
    {
        return $this->nome;
    }

    public function setNome(string $nome): static
    {
        $this->nome = $nome;

        return $this;
    }

    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    public function setDescricao(?string $descricao): static
    {
        $this->descricao = $descricao;

        return $this;
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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getParticipantes(): Collection
    {
        return $this->participantes;
    }

    public function adicionarParticipante(User $user): void
    {
        if (!$this->participantes->contains($user)) {
            $this->participantes->add($user);
        }
    }

    public function removerParticipante(User $user): void
    {
        $this->participantes->removeElement($user);
    }

    public function temAcesso(User $user): bool
    {
        return $this->criadoPor === $user || $this->participantes->contains($user);
    }

    public function getColunas(): Collection
    {
        return $this->colunas;
    }

    public function getCriadoEm(): ?\DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }
}
