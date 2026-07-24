<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\DTO\ComentarioOutput;
use App\Kanban\DTO\CriarComentarioInput;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Repository\KanbanComentarioRepository;
use App\Kanban\Service\SanitizadorTextoKanban;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AtualizarComentarioUseCase
{
    public function __construct(
        private readonly KanbanComentarioRepository $comentarioRepository,
        private readonly SanitizadorTextoKanban $sanitizador,
    ) {
    }

    public function executar(KanbanComentario $comentario, CriarComentarioInput $input, User $usuarioAtual): ComentarioOutput
    {
        if (!$comentario->pertenceAo($usuarioAtual)) {
            throw new AccessDeniedException('Você só pode editar seus próprios comentários.');
        }

        // Mesma limpeza da criação: a edição é outra porta de entrada para o mesmo campo.
        if ($this->sanitizador->estaVazio($input->conteudo)) {
            throw new \InvalidArgumentException('O comentário não pode ser vazio.');
        }

        $comentario->setConteudo($this->sanitizador->limpar($input->conteudo) ?? '');
        $this->comentarioRepository->salvar($comentario, flush: true);

        return ComentarioOutput::fromEntity($comentario, $usuarioAtual);
    }
}
