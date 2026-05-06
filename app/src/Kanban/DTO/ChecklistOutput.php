<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Kanban\Entity\KanbanChecklist;

final class ChecklistOutput
{
    /**
     * @param ChecklistItemOutput[] $itens
     */
    public function __construct(
        public readonly int $id,
        public readonly string $titulo,
        public readonly int $posicao,
        public readonly array $itens,
        public readonly int $progresso,
        public readonly int $totalItens,
        public readonly int $itensConcluidos,
    ) {
    }

    public static function fromEntity(KanbanChecklist $checklist): self
    {
        $itens = array_map(
            fn($item) => ChecklistItemOutput::fromEntity($item),
            $checklist->getItens()->toArray()
        );

        return new self(
            id: $checklist->getId(),
            titulo: $checklist->getTitulo(),
            posicao: $checklist->getPosicao(),
            itens: $itens,
            progresso: $checklist->progresso(),
            totalItens: $checklist->totalItens(),
            itensConcluidos: $checklist->itensConcluidosCount(),
        );
    }
}
