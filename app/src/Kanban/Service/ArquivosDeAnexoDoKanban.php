<?php

declare(strict_types=1);

namespace App\Kanban\Service;

use App\Kanban\Armazenamento\ChavesDeKanban;
use App\Kanban\Entity\KanbanAnexo;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;

/**
 * Dono dos arquivos dos anexos do Kanban — de perguntar por eles e de apagá-los junto com a linha.
 *
 * Duas coisas que estavam erradas antes da E1 e que este serviço centraliza:
 *
 * 1. `KanbanAnexo::getCaminho()` guarda só o NOME do arquivo, mas `ExcluirAnexoUseCase` e
 *    `KanbanAnexoController` o tratavam como caminho completo.
 * 2. `KanbanCard` declara `cascade: ['remove'], orphanRemoval: true` sobre os anexos
 *    (`KanbanCard.php:69-71`), e `KanbanColuna`/`KanbanBoard` cascateiam em cadeia até lá.
 *    Excluir card ou mural apagava as LINHAS sem passar pelo UseCase do anexo — o arquivo ficava
 *    no disco para sempre. Por isso existem `chavesDoCard()` e `chavesDoBoard()`.
 *
 * **A ordem mudou na E2.5 (INV-6).** Antes, o disco era limpo ANTES de o ORM cascatear, e um
 * `flush` recusado deixava o anexo de pé apontando para arquivo apagado. Agora são dois passos, e
 * os UseCases os chamam nesta ordem: coletar as chaves (antes do `remove`, enquanto a cadeia de
 * coleções ainda é de entidades vivas) → remover e confirmar → {@see remover()}.
 *
 * Desde a E2.5 o serviço não conhece mais caminho de disco nem o storage antigo: presença e
 * remoção são por chave (`ChavesDeKanban`, escopo do próprio anexo).
 */
final class ArquivosDeAnexoDoKanban
{
    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function existe(KanbanAnexo $anexo): bool
    {
        return $this->armazenamento->existe(ChavesDeKanban::anexo($anexo));
    }

    /** @return list<ChaveDeArquivo> */
    public function chavesDoAnexo(KanbanAnexo $anexo): array
    {
        return [ChavesDeKanban::anexo($anexo)];
    }

    /** @return list<ChaveDeArquivo> */
    public function chavesDoCard(KanbanCard $card): array
    {
        $chaves = [];
        foreach ($card->getAnexos() as $anexo) {
            $chaves[] = ChavesDeKanban::anexo($anexo);
        }

        return $chaves;
    }

    /** @return list<ChaveDeArquivo> */
    public function chavesDoBoard(KanbanBoard $board): array
    {
        $chaves = [];
        foreach ($board->getColunas() as $coluna) {
            foreach ($coluna->getCards() as $card) {
                array_push($chaves, ...$this->chavesDoCard($card));
            }
        }

        return $chaves;
    }

    /**
     * Só DEPOIS do COMMIT. Falha física vira registro no log e órfão recuperável — a exclusão no
     * banco já está confirmada e não é desfeita.
     *
     * @param list<ChaveDeArquivo> $chaves
     */
    public function remover(array $chaves, string $contexto): void
    {
        $this->remocao->remover($chaves, $contexto);
    }
}
