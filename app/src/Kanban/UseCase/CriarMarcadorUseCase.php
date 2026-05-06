<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\DTO\CriarMarcadorInput;
use App\Kanban\DTO\MarcadorOutput;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanMarcador;
use App\Kanban\Repository\KanbanMarcadorRepository;

final class CriarMarcadorUseCase
{
    public function __construct(
        private readonly KanbanMarcadorRepository $marcadorRepository,
    ) {
    }

    public function executar(CriarMarcadorInput $input, KanbanBoard $board): MarcadorOutput
    {
        $marcador = new KanbanMarcador($input->nome, $input->cor, $board);
        $this->marcadorRepository->salvar($marcador, flush: true);

        return MarcadorOutput::fromEntity($marcador);
    }
}
