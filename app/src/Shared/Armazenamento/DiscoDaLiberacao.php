<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * O disco da liberação (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.3 e §5.4), endereçado por
 * chave e lote. A implementação é a {@see AreaDeLiberados}; a interface existe para os testes provarem
 * as falhas entre um passo e outro (regravação no meio, falha depois de mover).
 */
interface DiscoDaLiberacao
{
    /** Tamanho, `mtime` e `atime` do arquivo no lugar dele; null quando não há arquivo regular ali. */
    public function noDisco(ChaveDeArquivo $chave): ?ArquivoNoDisco;

    public function estaNosLiberados(ChaveDeArquivo $chave, string $lote): bool;

    /**
     * @throws ArquivoNaoEncontrado
     * @throws FalhaDeArmazenamento
     */
    public function sha256NoDisco(ChaveDeArquivo $chave): string;

    /**
     * @throws ArquivoNaoEncontrado
     * @throws FalhaDeArmazenamento
     */
    public function sha256NosLiberados(ChaveDeArquivo $chave, string $lote): string;

    /**
     * @param \Closure(resource): void $escrever
     *
     * @return array{sha256: string, tamanhoBytes: int}
     */
    public function medirDownload(\Closure $escrever): array;

    /** @throws FalhaDeArmazenamento */
    public function moverParaLiberados(ChaveDeArquivo $chave, string $lote): MovimentoNoDisco;

    /** @throws FalhaDeArmazenamento */
    public function devolverDosLiberados(ChaveDeArquivo $chave, string $lote): MovimentoNoDisco;

    /**
     * @param \Closure(resource): void $escrever
     *
     * @throws FalhaDeArmazenamento
     */
    public function publicarNoOriginal(ChaveDeArquivo $chave, \Closure $escrever, string $sha256, int $tamanhoBytes): MovimentoNoDisco;

    /**
     * Apaga a cópia de um lote: só a EXCLUSÃO pedida (usuário, purga) chama. Ausente conta como apagada.
     *
     * @throws FalhaDeArmazenamento
     */
    public function apagarDosLiberados(ChaveDeArquivo $chave, string $lote): void;
}
