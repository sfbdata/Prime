<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Kanban\Entity\KanbanBoard;

final class BoardOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly ?string $descricao,
        public readonly ?string $cor,
        public readonly string $criadoPorNome,
        public readonly int $participantesCount,
        public readonly int $cardsCount,
        public readonly \DateTimeImmutable $criadoEm,
    ) {
    }

    public static function fromEntity(KanbanBoard $board): self
    {
        $cardsCount = 0;
        foreach ($board->getColunas() as $coluna) {
            $cardsCount += $coluna->contarCards();
        }

        $criadoPor = $board->getCriadoPor();

        return new self(
            id: $board->getId(),
            nome: $board->getNome(),
            descricao: $board->getDescricao(),
            cor: $board->getCor(),
            criadoPorNome: $criadoPor ? ($criadoPor->getFullName() ?? $criadoPor->getEmail()) : '',
            participantesCount: $board->getParticipantes()->count(),
            cardsCount: $cardsCount,
            criadoEm: $board->getCriadoEm() ?? new \DateTimeImmutable(),
        );
    }
}
