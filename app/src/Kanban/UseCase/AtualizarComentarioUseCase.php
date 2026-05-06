<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\DTO\ComentarioOutput;
use App\Kanban\DTO\CriarComentarioInput;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Repository\KanbanComentarioRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AtualizarComentarioUseCase
{
    public function __construct(
        private readonly KanbanComentarioRepository $comentarioRepository,
    ) {
    }

    public function executar(KanbanComentario $comentario, CriarComentarioInput $input, User $usuarioAtual): ComentarioOutput
    {
        if (!$comentario->pertenceAo($usuarioAtual)) {
            throw new AccessDeniedException('Você só pode editar seus próprios comentários.');
        }

        $comentario->setConteudo($input->conteudo);
        $this->comentarioRepository->salvar($comentario, flush: true);

        return ComentarioOutput::fromEntity($comentario, $usuarioAtual);
    }
}
