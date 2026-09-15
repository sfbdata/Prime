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
        // `getCaminho()` guarda só o nome: o diretório é recomposto pelo serviço.
        $this->arquivos->removerDoAnexo($anexo);

        $this->anexoRepository->remover($anexo, flush: true);
    }
}
