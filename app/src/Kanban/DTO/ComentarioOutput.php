<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Entity\Auth\User;
use App\Kanban\Entity\KanbanComentario;

final class ComentarioOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $conteudo,
        public readonly string $autorNome,
        public readonly int $autorId,
        public readonly \DateTimeImmutable $criadoEm,
        public readonly ?\DateTimeImmutable $atualizadoEm,
        public readonly bool $podeEditar,
    ) {
    }

    public static function fromEntity(KanbanComentario $comentario, User $usuarioAtual): self
    {
        $criadoPor = $comentario->getCriadoPor();

        return new self(
            id: $comentario->getId(),
            conteudo: $comentario->getConteudo(),
            autorNome: $criadoPor ? ($criadoPor->getFullName() ?? $criadoPor->getEmail()) : '',
            autorId: $criadoPor?->getId() ?? 0,
            criadoEm: $comentario->getCriadoEm() ?? new \DateTimeImmutable(),
            atualizadoEm: $comentario->getAtualizadoEm(),
            podeEditar: $comentario->pertenceAo($usuarioAtual),
        );
    }
}
