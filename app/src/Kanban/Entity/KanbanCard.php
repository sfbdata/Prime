<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Entity\Auth\User;
use App\Kanban\Repository\KanbanCardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanCardRepository::class)]
#[ORM\Table(name: 'kanban_card')]
#[ORM\HasLifecycleCallbacks]
class KanbanCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $titulo;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descricao = null;

    #[ORM\Column(type: 'integer')]
    private int $posicao = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataVencimento = null;

    #[ORM\ManyToOne(inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KanbanColuna $coluna = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KanbanBoard $board = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $criadoPor = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'kanban_card_responsavel')]
    private Collection $responsaveis;

    #[ORM\ManyToMany(targetEntity: KanbanMarcador::class)]
    #[ORM\JoinTable(name: 'kanban_card_marcador')]
    private Collection $marcadores;

    #[ORM\OneToMany(mappedBy: 'card', targetEntity: KanbanChecklist::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['posicao' => 'ASC'])]
    private Collection $checklists;

    #[ORM\OneToMany(mappedBy: 'card', targetEntity: KanbanAnexo::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $anexos;

    #[ORM\OneToMany(mappedBy: 'card', targetEntity: KanbanComentario::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['criadoEm' => 'ASC'])]
    private Collection $comentarios;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $criadoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(string $titulo, KanbanColuna $coluna, KanbanBoard $board, User $criadoPor, int $posicao = 0)
    {
        $this->titulo      = $titulo;
        $this->coluna      = $coluna;
        $this->board       = $board;
        $this->criadoPor   = $criadoPor;
        $this->posicao     = $posicao;
        $this->responsaveis = new ArrayCollection();
        $this->marcadores  = new ArrayCollection();
        $this->checklists  = new ArrayCollection();
        $this->anexos      = new ArrayCollection();
        $this->comentarios = new ArrayCollection();
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

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): static
    {
        $this->titulo = $titulo;

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

    public function getPosicao(): int
    {
        return $this->posicao;
    }

    public function setPosicao(int $posicao): static
    {
        $this->posicao = $posicao;

        return $this;
    }

    public function getDataVencimento(): ?\DateTimeImmutable
    {
        return $this->dataVencimento;
    }

    public function setDataVencimento(?\DateTimeImmutable $dataVencimento): static
    {
        $this->dataVencimento = $dataVencimento;

        return $this;
    }

    public function getColuna(): ?KanbanColuna
    {
        return $this->coluna;
    }

    public function setColuna(KanbanColuna $coluna): static
    {
        $this->coluna = $coluna;

        return $this;
    }

    public function getBoard(): ?KanbanBoard
    {
        return $this->board;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getResponsaveis(): Collection
    {
        return $this->responsaveis;
    }

    public function adicionarResponsavel(User $user): void
    {
        if (!$this->responsaveis->contains($user)) {
            $this->responsaveis->add($user);
        }
    }

    public function removerResponsavel(User $user): void
    {
        $this->responsaveis->removeElement($user);
    }

    public function getMarcadores(): Collection
    {
        return $this->marcadores;
    }

    public function adicionarMarcador(KanbanMarcador $marcador): void
    {
        if (!$this->marcadores->contains($marcador)) {
            $this->marcadores->add($marcador);
        }
    }

    public function removerMarcador(KanbanMarcador $marcador): void
    {
        $this->marcadores->removeElement($marcador);
    }

    public function getChecklists(): Collection
    {
        return $this->checklists;
    }

    public function getAnexos(): Collection
    {
        return $this->anexos;
    }

    public function getComentarios(): Collection
    {
        return $this->comentarios;
    }

    public function getCriadoEm(): ?\DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function estaVencido(): bool
    {
        if ($this->dataVencimento === null) {
            return false;
        }

        return $this->dataVencimento < new \DateTimeImmutable('today');
    }

    public function progressoChecklists(): int
    {
        $total    = 0;
        $concluidos = 0;

        foreach ($this->checklists as $checklist) {
            foreach ($checklist->getItens() as $item) {
                ++$total;
                if ($item->isConcluido()) {
                    ++$concluidos;
                }
            }
        }

        if ($total === 0) {
            return 0;
        }

        return (int) round(($concluidos / $total) * 100);
    }
}
