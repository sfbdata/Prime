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
        private readonly string $kanbanUploadsDir,
    ) {
    }

    public function executar(UploadedFile $arquivo, KanbanCard $card, User $usuario): AnexoOutput
    {
        // Os metadados são lidos ANTES de salvar. `ArquivoStorageService::salvar()` chama
        // `UploadedFile::move()`, que MOVE o temporário do PHP; depois disso o objeto ainda
        // aponta para o caminho antigo e `getSize()`/`getMimeType()` estouram
        // "stat failed". Era esse o motivo de todo upload de anexo do Kanban terminar em 500 —
        // e de `kanban_anexo` estar vazia em produção.
        $nomeOriginal = $arquivo->getClientOriginalName() ?: 'arquivo';
        $tamanho      = $arquivo->getSize() ?: 0;
        $mimeType     = $arquivo->getMimeType() ?? 'application/octet-stream';

        // O diretório vem do container (`%kanban_uploads_dir%`), absoluto. Antes da E1 era o
        // literal 'kanban', relativo ao WORKDIR do PHP-FPM — fora do volume persistido.
        // `salvar()` devolve só o NOME; é isso que a coluna guarda.
        $caminho = $this->storage->salvar($arquivo, $this->kanbanUploadsDir);

        $anexo = new KanbanAnexo(
            nomeOriginal: $nomeOriginal,
            caminho: $caminho,
            tamanho: $tamanho,
            mimeType: $mimeType,
            card: $card,
            criadoPor: $usuario,
        );

        $this->anexoRepository->salvar($anexo, flush: true);

        return AnexoOutput::fromEntity($anexo);
    }
}
