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
        $tenant    = $comentario->getCard()?->getBoard()?->getTenant();
        $eAdmin    = $tenant !== null && $this->permissionChecker->canAdminister($usuarioAtual, $tenant, 'kanban');
        $podeExcluir = $comentario->pertenceAo($usuarioAtual) || $eAdmin;

        if (!$podeExcluir) {
            throw new AccessDeniedException('Você só pode excluir seus próprios comentários.');
        }

        $this->comentarioRepository->remover($comentario, flush: true);
    }
}
