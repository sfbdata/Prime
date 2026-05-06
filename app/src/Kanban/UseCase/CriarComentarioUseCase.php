<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\DTO\ComentarioOutput;
use App\Kanban\DTO\CriarComentarioInput;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Repository\KanbanComentarioRepository;

final class CriarComentarioUseCase
{
    public function __construct(
        private readonly KanbanComentarioRepository $comentarioRepository,
    ) {
    }

    public function executar(CriarComentarioInput $input, KanbanCard $card, User $usuario): ComentarioOutput
    {
        $comentario = new KanbanComentario($input->conteudo, $card, $usuario);
        $this->comentarioRepository->salvar($comentario, flush: true);

        return ComentarioOutput::fromEntity($comentario, $usuario);
    }
}
