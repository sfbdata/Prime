<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Kanban\Entity\KanbanCard;

final class CardSummaryOutput
{
    /**
     * @param MarcadorOutput[]  $marcadores
     * @param UsuarioOutput[]   $responsaveis
     */
    public function __construct(
        public readonly int $id,
        public readonly string $titulo,
        public readonly int $posicao,
        public readonly ?\DateTimeImmutable $dataVencimento,
        public readonly bool $estaVencido,
        public readonly int $progressoChecklists,
        public readonly int $totalChecklists,
        public readonly int $totalAnexos,
        public readonly array $marcadores,
        public readonly array $responsaveis,
    ) {
    }

    public static function fromEntity(KanbanCard $card): self
    {
        return new self(
            id: $card->getId(),
            titulo: $card->getTitulo(),
            posicao: $card->getPosicao(),
            dataVencimento: $card->getDataVencimento(),
            estaVencido: $card->estaVencido(),
            progressoChecklists: $card->progressoChecklists(),
            totalChecklists: $card->getChecklists()->count(),
            totalAnexos: $card->getAnexos()->count(),
            marcadores: array_map(fn($m) => MarcadorOutput::fromEntity($m), $card->getMarcadores()->toArray()),
            responsaveis: array_map(fn($u) => UsuarioOutput::fromEntity($u), $card->getResponsaveis()->toArray()),
        );
    }
}
