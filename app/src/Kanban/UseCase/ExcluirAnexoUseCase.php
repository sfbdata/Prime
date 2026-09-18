<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Repository\KanbanAnexoRepository;
use App\Kanban\Service\ArquivosDeAnexoDoKanban;

final class ExcluirAnexoUseCase
{
    public function __construct(
        private readonly KanbanAnexoRepository $anexoRepository,
        private readonly ArquivosDeAnexoDoKanban $arquivos,
    ) {
    }

    public function executar(KanbanAnexo $anexo): void
    {
        // A chave antes, o arquivo só depois do COMMIT (E2.5, INV-6).
        $chaves = $this->arquivos->chavesDoAnexo($anexo);

        $this->anexoRepository->remover($anexo, flush: true);

        $this->arquivos->remover($chaves, 'ExcluirAnexoUseCase');
    }
}
