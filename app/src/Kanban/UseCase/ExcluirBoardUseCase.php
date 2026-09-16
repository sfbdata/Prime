<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Repository\KanbanBoardRepository;
use App\Kanban\Service\ArquivosDeAnexoDoKanban;
use App\Service\PermissionChecker;

final class ExcluirBoardUseCase
{
    public function __construct(
        private readonly KanbanBoardRepository $boardRepository,
        private readonly ArquivosDeAnexoDoKanban $arquivos,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function executar(KanbanBoard $board, User $usuarioAtual): void
    {
        $eCriador = $board->getCriadoPor() === $usuarioAtual;
        $tenant   = $board->getTenant();
        $eAdmin   = $tenant !== null && $this->permissionChecker->canAdminister($usuarioAtual, $tenant, 'kanban');

        if (!$eCriador && !$eAdmin) {
            throw new \RuntimeException('Apenas o criador ou administrador pode excluir o mural.');
        }

        // Cascade em cadeia: board -> colunas -> cards -> anexos. As chaves saem antes do remove,
        // enquanto a cadeia é de entidades vivas; os arquivos, só depois do COMMIT (E2.5, INV-6).
        $chaves = $this->arquivos->chavesDoBoard($board);

        $this->boardRepository->remover($board, flush: true);

        $this->arquivos->remover($chaves, 'ExcluirBoardUseCase');
    }
}
