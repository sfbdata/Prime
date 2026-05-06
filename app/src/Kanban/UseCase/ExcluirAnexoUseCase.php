<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Repository\KanbanAnexoRepository;
use App\Shared\Service\ArquivoStorageInterface;

final class ExcluirAnexoUseCase
{
    public function __construct(
        private readonly KanbanAnexoRepository $anexoRepository,
        private readonly ArquivoStorageInterface $storage,
    ) {
    }

    public function executar(KanbanAnexo $anexo): void
    {
        if ($this->storage->existe($anexo->getCaminho())) {
            $this->storage->excluir($anexo->getCaminho());
        }

        $this->anexoRepository->remover($anexo, flush: true);
    }
}
