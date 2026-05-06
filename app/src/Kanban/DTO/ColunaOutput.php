<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Kanban\Entity\KanbanColuna;

final class ColunaOutput
{
    /**
     * @param CardSummaryOutput[] $cards
     */
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $tipo,
        public readonly int $posicao,
        public readonly array $cards,
        public readonly int $totalCards,
    ) {
    }

    public static function fromEntity(KanbanColuna $coluna): self
    {
        $cards = array_map(
            fn($c) => CardSummaryOutput::fromEntity($c),
            $coluna->getCards()->toArray()
        );

        return new self(
            id: $coluna->getId(),
            nome: $coluna->getNome(),
            tipo: $coluna->getTipo(),
            posicao: $coluna->getPosicao(),
            cards: $cards,
            totalCards: count($cards),
        );
    }
}
