<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

/**
 * O que `ExcluirItensDaPastaUseCase` mandou para a LIXEIRA (D7). Nada sai do disco: a linha e o
 * arquivo físico ficam até a purga — por isso este resultado não carrega chave de arquivo nenhuma
 * (quem recebia as chaves para a `RemocaoAposTransacao` não tem mais o que remover).
 *
 *  - `documentosRemovidos`: os documentos selecionados DIRETAMENTE (fora os que estavam dentro de
 *    uma pasta também selecionada — esses contam em `arquivosRemovidos`);
 *  - `subpastasRemovidas`: as pastas selecionadas mais toda a descendência delas;
 *  - `arquivosRemovidos`: todos os arquivos que foram para a lixeira, estivessem onde estivessem;
 *  - `idsDocumentos`/`idsSecoes`: os ids que a tela mandou e que foram marcados — é o que o
 *    "Desfazer" do toast manda de volta ao restaurar;
 *  - `excluidoEm`: o carimbo único da ação; a subárvore inteira o compartilha.
 *
 * Os nomes `*Removidos` ficaram por contrato com a tela (as mesmas chaves do JSON de antes).
 */
final readonly class ResultadoExcluirItensDaPasta
{
    /**
     * @param list<int> $idsDocumentos
     * @param list<int> $idsSecoes
     */
    public function __construct(
        public int $documentosRemovidos,
        public int $subpastasRemovidas,
        public int $arquivosRemovidos,
        public array $idsDocumentos,
        public array $idsSecoes,
        public \DateTimeImmutable $excluidoEm,
    ) {
    }
}
