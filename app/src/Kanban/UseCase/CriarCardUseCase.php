<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\DTO\CriarCardInput;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Repository\KanbanCardRepository;

final class CriarCardUseCase
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepository,
    ) {
    }

    public function executar(CriarCardInput $input, KanbanColuna $coluna, User $criador): int
    {
        $posicao = $this->cardRepository->proximaPosicaoNaColuna($coluna);
        $card = new KanbanCard($input->titulo, $coluna, $coluna->getBoard(), $criador, $posicao);

        $this->cardRepository->salvar($card, flush: true);

        return $card->getId();
    }
}
