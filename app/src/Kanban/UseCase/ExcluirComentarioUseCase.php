<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Repository\KanbanComentarioRepository;
use App\Service\PermissionChecker;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ExcluirComentarioUseCase
{
    public function __construct(
        private readonly KanbanComentarioRepository $comentarioRepository,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    public function executar(KanbanComentario $comentario, User $usuarioAtual): void
    {
        $podeExcluir = $comentario->pertenceAo($usuarioAtual)
            || $this->permissionChecker->canAdminister($usuarioAtual, 'kanban');

        if (!$podeExcluir) {
            throw new AccessDeniedException('Você só pode excluir seus próprios comentários.');
        }

        $this->comentarioRepository->remover($comentario, flush: true);
    }
}
