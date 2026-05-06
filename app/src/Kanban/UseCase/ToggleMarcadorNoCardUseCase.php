<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanMarcador;
use App\Kanban\Repository\KanbanCardRepository;

final class ToggleMarcadorNoCardUseCase
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepository,
    ) {
    }

    public function executar(KanbanCard $card, KanbanMarcador $marcador): bool
    {
        if ($card->getMarcadores()->contains($marcador)) {
            $card->removerMarcador($marcador);
            $this->cardRepository->salvar($card, flush: true);

            return false;
        }

        $card->adicionarMarcador($marcador);
        $this->cardRepository->salvar($card, flush: true);

        return true;
    }
}
