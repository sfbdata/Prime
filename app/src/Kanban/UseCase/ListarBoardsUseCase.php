<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\DTO\BoardOutput;
use App\Kanban\Repository\KanbanBoardRepository;

final class ListarBoardsUseCase
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepository,
    ) {
    }

    /** @return BoardOutput[] */
    public function executar(User $user, Tenant $tenant): array
    {
        $boards = $this->boardRepository->findAcessiveisPorUsuario($user, $tenant);

        return array_map(fn($b) => BoardOutput::fromEntity($b), $boards);
    }
}
