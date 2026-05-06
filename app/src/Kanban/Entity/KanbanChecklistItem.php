<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Kanban\Repository\KanbanChecklistItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanChecklistItemRepository::class)]
#[ORM\Table(name: 'kanban_checklist_item')]
class KanbanChecklistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private string $texto;

    #[ORM\Column]
    private bool $concluido = false;

    #[ORM\Column(type: 'integer')]
    private int $posicao = 0;

    #[ORM\ManyToOne(inversedBy: 'itens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KanbanChecklist $checklist = null;

    public function __construct(string $texto, KanbanChecklist $checklist, int $posicao = 0)
    {
        $this->texto     = $texto;
        $this->checklist = $checklist;
        $this->posicao   = $posicao;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTexto(): string
    {
        return $this->texto;
    }

    public function setTexto(string $texto): static
    {
        $this->texto = $texto;

        return $this;
    }

    public function isConcluido(): bool
    {
        return $this->concluido;
    }

    public function setConcluido(bool $concluido): static
    {
        $this->concluido = $concluido;

        return $this;
    }

    public function toggle(): void
    {
        $this->concluido = !$this->concluido;
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

    public function getChecklist(): ?KanbanChecklist
    {
        return $this->checklist;
    }
}
