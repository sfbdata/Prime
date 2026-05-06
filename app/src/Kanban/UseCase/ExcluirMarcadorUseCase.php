<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\Entity\KanbanMarcador;
use App\Kanban\Repository\KanbanMarcadorRepository;

final class ExcluirMarcadorUseCase
{
    public function __construct(
        private readonly KanbanMarcadorRepository $marcadorRepository,
    ) {
    }

    public function executar(KanbanMarcador $marcador): void
    {
        $this->marcadorRepository->remover($marcador, flush: true);
    }
}
