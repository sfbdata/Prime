<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\DTO\ChecklistItemOutput;
use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Repository\KanbanChecklistItemRepository;

final class MarcarItemChecklistUseCase
{
    public function __construct(
        private readonly KanbanChecklistItemRepository $itemRepository,
    ) {
    }

    public function executar(KanbanChecklistItem $item): ChecklistItemOutput
    {
        $item->toggle();
        $this->itemRepository->salvar($item, flush: true);

        return ChecklistItemOutput::fromEntity($item);
    }
}
