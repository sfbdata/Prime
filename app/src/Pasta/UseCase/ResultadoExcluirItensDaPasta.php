<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O que `ExcluirItensDaPastaUseCase` tirou do banco — e as chaves dos arquivos que agora podem
 * sair do disco, PELA `RemocaoAposTransacao`, depois do COMMIT (INV-6). O UseCase não apaga
 * arquivo: só diz quais ficaram órfãos.
 *
 *  - `documentosRemovidos`: os documentos selecionados DIRETAMENTE (fora os que estavam dentro de
 *    uma pasta também selecionada — esses contam em `arquivosRemovidos`);
 *  - `subpastasRemovidas`: as pastas selecionadas mais toda a descendência delas;
 *  - `arquivosRemovidos`: todos os arquivos que saíram, estivessem onde estivessem.
 */
final readonly class ResultadoExcluirItensDaPasta
{
    /** @param list<ChaveDeArquivo> $chaves */
    public function __construct(
        public array $chaves,
        public int $documentosRemovidos,
        public int $subpastasRemovidas,
        public int $arquivosRemovidos,
    ) {
    }
}
