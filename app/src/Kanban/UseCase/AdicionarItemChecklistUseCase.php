<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\DTO\AdicionarItemChecklistInput;
use App\Kanban\DTO\ChecklistItemOutput;
use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Entity\KanbanChecklistItem;
use App\Kanban\Repository\KanbanChecklistItemRepository;

final class AdicionarItemChecklistUseCase
{
    public function __construct(
        private readonly KanbanChecklistItemRepository $itemRepository,
    ) {
    }

    public function executar(AdicionarItemChecklistInput $input, KanbanChecklist $checklist): ChecklistItemOutput
    {
        $posicao = $this->itemRepository->proximaPosicaoNoChecklist($checklist);
        $item    = new KanbanChecklistItem($input->texto, $checklist, $posicao);
        $this->itemRepository->salvar($item, flush: true);

        return ChecklistItemOutput::fromEntity($item);
    }
}
