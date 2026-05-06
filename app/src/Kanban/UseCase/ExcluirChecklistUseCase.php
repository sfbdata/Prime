<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Repository\KanbanChecklistRepository;

final class ExcluirChecklistUseCase
{
    public function __construct(
        private readonly KanbanChecklistRepository $checklistRepository,
    ) {
    }

    public function executar(KanbanChecklist $checklist): void
    {
        $this->checklistRepository->remover($checklist, flush: true);
    }
}
