<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\DTO\MoverCardInput;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\Repository\KanbanColunaRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class MoverCardUseCase
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepository,
        private readonly KanbanColunaRepository $colunaRepository,
    ) {
    }

    public function executar(KanbanCard $card, MoverCardInput $input, User $usuarioAtual): void
    {
        $board = $card->getBoard();

        if (!$board->temAcesso($usuarioAtual)) {
            throw new AccessDeniedException('Sem acesso a este mural.');
        }

        $novaColuna = $this->colunaRepository->findPorBoardEId($input->novaColunaId, $board);

        if ($novaColuna === null) {
            throw new \InvalidArgumentException('Coluna inválida para este mural.');
        }

        $card->setColuna($novaColuna);
        $card->setPosicao($input->novaPosicao);

        $this->cardRepository->salvar($card, flush: true);
    }
}
