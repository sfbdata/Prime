<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\DTO\CriarChecklistInput;
use App\Kanban\DTO\ChecklistOutput;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanChecklist;
use App\Kanban\Repository\KanbanChecklistRepository;

final class CriarChecklistUseCase
{
    public function __construct(
        private readonly KanbanChecklistRepository $checklistRepository,
    ) {
    }

    public function executar(CriarChecklistInput $input, KanbanCard $card): ChecklistOutput
    {
        $posicao   = $this->checklistRepository->proximaPosicaoNoCard($card);
        $checklist = new KanbanChecklist($input->titulo, $card, $posicao);
        $this->checklistRepository->salvar($checklist, flush: true);

        return ChecklistOutput::fromEntity($checklist);
    }
}
