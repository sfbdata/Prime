<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Repository\KanbanChecklistItemRepository;

final class RemoverItemChecklistUseCase
{
    public function __construct(
        private readonly KanbanChecklistItemRepository $itemRepository,
    ) {
    }

    public function executar(KanbanChecklistItem $item): void
    {
        $this->itemRepository->remover($item, flush: true);
    }
}
