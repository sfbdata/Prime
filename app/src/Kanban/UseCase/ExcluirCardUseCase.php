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
        // `KanbanCard` cascateia `remove` + `orphanRemoval` sobre os anexos: as linhas somem sem
        // passar pelo ExcluirAnexoUseCase. As chaves são coletadas ANTES do remove; os arquivos
        // saem só depois do COMMIT (E2.5, INV-6).
        $chaves = $this->arquivos->chavesDoCard($card);

        $this->cardRepository->remover($card, flush: true);

        $this->arquivos->remover($chaves, 'ExcluirCardUseCase');
    }
}
