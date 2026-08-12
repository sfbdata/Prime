<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Entity\Tenant\Tenant;
use App\Kanban\Repository\KanbanChecklistItemRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanChecklistItemRepository::class)]
#[ORM\Table(name: 'kanban_checklist_item')]
class KanbanChecklistItem implements TenantAware
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

    /**
     * Denormalizado do checklist dono, para o TenantFilter alcancar esta entidade. Nunca recebido
     * por parametro: o construtor deriva do pai, e por isso nao existe caminho para divergir.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(inversedBy: 'itens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?KanbanChecklist $checklist = null;

    public function __construct(string $texto, KanbanChecklist $checklist, int $posicao = 0)
    {
        $this->texto     = $texto;
        $this->checklist = $checklist;

        $tenant = $checklist->getTenant();
        if ($tenant === null) {
            throw new \InvalidArgumentException(
                'Nao e possivel criar KanbanChecklistItem a partir de um checklist sem escritorio.'
            );
        }
        $this->tenant = $tenant;
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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }
}
