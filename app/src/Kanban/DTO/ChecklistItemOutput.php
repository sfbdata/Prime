<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Kanban\Entity\KanbanChecklistItem;

final class ChecklistItemOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $texto,
        public readonly bool $concluido,
        public readonly int $posicao,
    ) {
    }

    public static function fromEntity(KanbanChecklistItem $item): self
    {
        return new self(
            id: $item->getId(),
            texto: $item->getTexto(),
            concluido: $item->isConcluido(),
            posicao: $item->getPosicao(),
        );
    }
}
