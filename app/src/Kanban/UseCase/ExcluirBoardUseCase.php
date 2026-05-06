<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Repository\KanbanBoardRepository;
use App\Service\PermissionChecker;

final class ExcluirBoardUseCase
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function executar(KanbanBoard $board, User $usuarioAtual): void
    {
        $eCriador = $board->getCriadoPor() === $usuarioAtual;
        $eAdmin   = $this->permissionChecker->canAdminister($usuarioAtual, 'kanban');

        if (!$eCriador && !$eAdmin) {
            throw new \RuntimeException('Apenas o criador ou administrador pode excluir o mural.');
        }

        $this->boardRepository->remover($board, flush: true);
    }
}
