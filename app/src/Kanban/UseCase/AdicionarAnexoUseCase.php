<?php

declare(strict_types=1);

namespace App\Kanban\UseCase;

use App\Entity\Auth\User;
use App\Kanban\Armazenamento\ChavesDeKanban;
use App\Kanban\DTO\AnexoOutput;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Repository\KanbanAnexoRepository;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\FonteDeUploadHttp;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdicionarAnexoUseCase
{
    public function __construct(
        private readonly KanbanAnexoRepository $anexoRepository,
        private readonly ArmazenamentoDeArquivos $armazenamento,
    ) {
    }

    public function executar(UploadedFile $arquivo, KanbanCard $card, User $usuario): AnexoOutput
    {
        // Os metadados são lidos ANTES de gravar. A gravação chama `UploadedFile::move()`, que
        // MOVE o temporário do PHP; depois disso o objeto ainda aponta para o caminho antigo e
        // `getSize()`/`getMimeType()` estouram "stat failed". Era esse o motivo de todo upload de
        // anexo do Kanban terminar em 500 — e de `kanban_anexo` estar vazia em produção.
        $nomeOriginal = $arquivo->getClientOriginalName() ?: 'arquivo';
        $tamanho      = $arquivo->getSize() ?: 0;
        $mimeType     = $arquivo->getMimeType() ?? 'application/octet-stream';

        // O diretório é resolvido pelo storage a partir da categoria KANBAN_ANEXO
        // (`%kanban_uploads_dir%`, absoluto). Antes da E1 era o literal 'kanban', relativo ao
        // WORKDIR do PHP-FPM — fora do volume persistido. O escopo sai do CARD (R1). A coluna
        // guarda só o NOME cunhado, sem diretório.
        $upload     = FonteDeUploadHttp::de($arquivo);
        $armazenado = $upload->gravarEm($this->armazenamento, ChavesDeKanban::novoAnexo($card, $upload->extensao));
        $caminho    = $armazenado->chave->nome;

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
