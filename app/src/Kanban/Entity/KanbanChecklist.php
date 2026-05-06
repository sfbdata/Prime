<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Kanban\Repository\KanbanChecklistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanChecklistRepository::class)]
#[ORM\Table(name: 'kanban_checklist')]
class KanbanChecklist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(type: 'integer')]
    private int $posicao = 0;

    #[ORM\ManyToOne(inversedBy: 'checklists')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KanbanCard $card = null;

    #[ORM\OneToMany(mappedBy: 'checklist', targetEntity: KanbanChecklistItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['posicao' => 'ASC'])]
    private Collection $itens;

    public function __construct(string $titulo, KanbanCard $card, int $posicao = 0)
    {
        $this->titulo  = $titulo;
        $this->card    = $card;
        $this->posicao = $posicao;
        $this->itens   = new ArrayCollection();
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

    public function getPosicao(): int
    {
        return $this->posicao;
    }

    public function getCard(): ?KanbanCard
    {
        return $this->card;
    }

    public function getItens(): Collection
    {
        return $this->itens;
    }

    public function totalItens(): int
    {
        return $this->itens->count();
    }

    public function itensConcluidosCount(): int
    {
        return $this->itens->filter(fn(KanbanChecklistItem $i) => $i->isConcluido())->count();
    }

    public function progresso(): int
    {
        $total = $this->totalItens();
        if ($total === 0) {
            return 0;
        }

        return (int) round(($this->itensConcluidosCount() / $total) * 100);
    }
}
