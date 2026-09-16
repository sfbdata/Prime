<?php

declare(strict_types=1);

namespace App\Kanban\Service;

use App\Kanban\Armazenamento\ChavesDeKanban;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
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
 *
 * Estado misto da E2.2 (D2): a PRESENÇA é perguntada ao armazenamento novo, por chave montada a
 * partir da entidade (`ChavesDeKanban`); a remoção ainda é da interface antiga, por caminho, até a
 * E2.5. O antigo `diretorio()` saiu na E2.2 (devolvia o diretório cru e não tinha consumidor), e
 * `caminhoDe()` virou privado na E2.3: o controller passou a entregar o anexo por chave, e o único
 * uso que sobrou do caminho é a remoção, aqui dentro.
 */
final class ArquivosDeAnexoDoKanban
{
    public function __construct(
        private readonly ArquivoStorageInterface $storage,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly string $kanbanUploadsDir,
    ) {
    }

    private function caminhoDe(KanbanAnexo $anexo): string
    {
        return $this->storage->caminho($this->kanbanUploadsDir, $anexo->getCaminho());
    }

    public function existe(KanbanAnexo $anexo): bool
    {
        return $this->armazenamento->existe(ChavesDeKanban::anexo($anexo));
    }

    public function removerDoAnexo(KanbanAnexo $anexo): void
    {
        if ($this->existe($anexo)) {
            $this->storage->excluir($this->caminhoDe($anexo));
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
