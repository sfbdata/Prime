<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Kanban\Entity\KanbanMarcador;

final class MarcadorOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $cor,
    ) {
    }

    public static function fromEntity(KanbanMarcador $marcador): self
    {
        return new self(
            id: $marcador->getId(),
            nome: $marcador->getNome(),
            cor: $marcador->getCor(),
        );
    }
}
