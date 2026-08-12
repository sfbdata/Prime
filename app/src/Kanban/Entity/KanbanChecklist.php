<?php

declare(strict_types=1);

namespace App\Kanban\Entity;

use App\Entity\Tenant\Tenant;
use App\Kanban\Repository\KanbanChecklistRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KanbanChecklistRepository::class)]
#[ORM\Table(name: 'kanban_checklist')]
class KanbanChecklist implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(type: 'integer')]
    private int $posicao = 0;

    /**
     * Denormalizado do card dono, para o TenantFilter alcancar esta entidade. Nunca recebido
     * por parametro: o construtor deriva do pai, e por isso nao existe caminho para divergir.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

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

        $tenant = $card->getTenant();
        if ($tenant === null) {
            throw new \InvalidArgumentException(
                'Nao e possivel criar KanbanChecklist a partir de um card sem escritorio.'
            );
        }
        $this->tenant = $tenant;
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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }
}
