<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\DTO\CriarMarcadorInput;
use App\Kanban\DTO\MarcadorOutput;
use App\Kanban\Entity\KanbanMarcador;
use App\Kanban\Repository\KanbanMarcadorRepository;

final class AtualizarMarcadorUseCase
{
    public function __construct(
        private readonly KanbanMarcadorRepository $marcadorRepository,
    ) {
    }

    public function executar(KanbanMarcador $marcador, CriarMarcadorInput $input): MarcadorOutput
    {
        $marcador->setNome($input->nome);
        $marcador->setCor($input->cor);
        $this->marcadorRepository->salvar($marcador, flush: true);

        return MarcadorOutput::fromEntity($marcador);
    }
}
