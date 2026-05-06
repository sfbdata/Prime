<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\Entity\KanbanCard;
use App\Kanban\Repository\KanbanCardRepository;

final class ExcluirCardUseCase
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepository,
    ) {
    }

    public function executar(KanbanCard $card): void
    {
        $this->cardRepository->remover($card, flush: true);
    }
}
