<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\DTO\ComentarioOutput;
use App\Kanban\DTO\CriarComentarioInput;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Repository\KanbanComentarioRepository;
use App\Kanban\Service\SanitizadorTextoKanban;

final class CriarComentarioUseCase
{
    public function __construct(
        private readonly KanbanComentarioRepository $comentarioRepository,
        private readonly SanitizadorTextoKanban $sanitizador,
    ) {
    }

    public function executar(CriarComentarioInput $input, KanbanCard $card, User $usuario): ComentarioOutput
    {
        // Vem do editor Quill (HTML): sanitizado ANTES de persistir. `estaVazio` porque o editor
        // entrega `<p><br></p>` quando nada foi digitado — não é string vazia.
        if ($this->sanitizador->estaVazio($input->conteudo)) {
            throw new \InvalidArgumentException('O comentário não pode ser vazio.');
        }

        $comentario = new KanbanComentario($this->sanitizador->limpar($input->conteudo) ?? '', $card, $usuario);
        $this->comentarioRepository->salvar($comentario, flush: true);

        return ComentarioOutput::fromEntity($comentario, $usuario);
    }
}
