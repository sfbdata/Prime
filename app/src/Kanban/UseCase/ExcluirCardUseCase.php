<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\Entity\KanbanCard;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\Service\ArquivosDeAnexoDoKanban;

final class ExcluirCardUseCase
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepository,
        private readonly ArquivosDeAnexoDoKanban $arquivos,
    ) {
    }

    public function executar(KanbanCard $card): void
    {
        // `KanbanCard` cascateia `remove` + `orphanRemoval` sobre os anexos: as linhas somem
        // sem passar pelo ExcluirAnexoUseCase. O disco tem de ser limpo ANTES.
        $this->arquivos->removerDoCard($card);

        $this->cardRepository->remover($card, flush: true);
    }
}
