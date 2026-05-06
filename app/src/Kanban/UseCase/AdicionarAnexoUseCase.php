<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\DTO\AnexoOutput;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Repository\KanbanAnexoRepository;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdicionarAnexoUseCase
{
    public function __construct(
        private readonly KanbanAnexoRepository $anexoRepository,
        private readonly ArquivoStorageInterface $storage,
    ) {
    }

    public function executar(UploadedFile $arquivo, KanbanCard $card, User $usuario): AnexoOutput
    {
        $caminho = $this->storage->salvar($arquivo, 'kanban');

        $anexo = new KanbanAnexo(
            nomeOriginal: $arquivo->getClientOriginalName() ?? 'arquivo',
            caminho: $caminho,
            tamanho: $arquivo->getSize() ?: 0,
            mimeType: $arquivo->getMimeType() ?? 'application/octet-stream',
            card: $card,
            criadoPor: $usuario,
        );

        $this->anexoRepository->salvar($anexo, flush: true);

        return AnexoOutput::fromEntity($anexo);
    }
}
