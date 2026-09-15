<?php

declare(strict_types=1);

namespace App\Kanban\Service;

use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Shared\Service\ArquivoStorageInterface;

/**
 * Dono do caminho em disco dos anexos do Kanban, e de apagá-los junto com a linha.
 *
 * Duas coisas que estavam erradas antes da E1 e que este serviço centraliza:
 *
 * 1. `KanbanAnexo::getCaminho()` guarda só o NOME do arquivo (é o retorno de
 *    `ArquivoStorageService::salvar()`), mas `ExcluirAnexoUseCase` e `KanbanAnexoController`
 *    o tratavam como caminho completo. O diretório precisa ser recomposto, como todos os outros
 *    domínios fazem.
 * 2. `KanbanCard` declara `cascade: ['remove'], orphanRemoval: true` sobre os anexos
 *    (`KanbanCard.php:69-71`), e `KanbanColuna`/`KanbanBoard` cascateiam em cadeia até lá.
 *    Excluir card ou mural apagava as LINHAS sem passar pelo UseCase do anexo — o arquivo ficava
 *    no disco para sempre. Por isso existem `removerDoCard()` e `removerDoBoard()`: o disco tem
 *    de ser limpo ANTES de o ORM cascatear.
 */
final class ArquivosDeAnexoDoKanban
{
    public function __construct(
        private readonly ArquivoStorageInterface $storage,
        private readonly string $kanbanUploadsDir,
    ) {
    }

    public function caminhoDe(KanbanAnexo $anexo): string
    {
        return $this->storage->caminho($this->kanbanUploadsDir, $anexo->getCaminho());
    }

    public function diretorio(): string
    {
        return $this->kanbanUploadsDir;
    }

    public function removerDoAnexo(KanbanAnexo $anexo): void
    {
        $caminho = $this->caminhoDe($anexo);

        if ($this->storage->existe($caminho)) {
            $this->storage->excluir($caminho);
        }
    }

    public function removerDoCard(KanbanCard $card): void
    {
        foreach ($card->getAnexos() as $anexo) {
            $this->removerDoAnexo($anexo);
        }
    }

    public function removerDoBoard(KanbanBoard $board): void
    {
        foreach ($board->getColunas() as $coluna) {
            foreach ($coluna->getCards() as $card) {
                $this->removerDoCard($card);
            }
        }
    }
}
