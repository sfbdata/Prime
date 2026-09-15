<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * O núcleo do armazenamento: seis verbos, endereçados por chave — nunca por caminho.
 *
 * ## O critério que decidiu o tamanho desta interface
 *
 * Entrou o que um backend remoto responde de forma DIFERENTE. Ficou de fora tudo o que ele não
 * responde de jeito nenhum: HTTP, autorização, tenant do request, auditoria, MIME de
 * apresentação, Content-Disposition, transação. Por isso são seis e não dezesseis — e por isso
 * listar/apagar por prefixo mora em {@see ArmazenamentoComPrefixo}, separada: ela só é segura em
 * duas das nove categorias (D7).
 *
 * Os seis cobrem os 40 usos de `ArquivoStorageInterface::caminho()` que existem hoje: `gravar`
 * substitui `salvar`/`salvarConteudo`/`moverParaArmazenamento` e o `file_put_contents` cru de
 * `EditarPecaTextoUseCase:22`; `abrir`/`ler` substituem os `file_get_contents` pendurados em
 * `caminho()`; `metadados` substitui os `filesize()` soltos.
 *
 * ## O que nenhuma implementação pode fazer
 *
 *  - **decidir quem pode ler** — autorização é da camada de cima, sempre (INV-5);
 *  - **normalizar a chave** — o nome é byte a byte o do banco (D8, ver {@see ChaveDeArquivo});
 *  - **depender de framework** — nada de `HttpFoundation` aqui dentro (INV-8);
 *  - **escolher o momento da remoção em relação ao COMMIT** — é do UseCase (INV-6): arquivo órfão
 *    recuperável é aceitável, registro válido apontando para arquivo inexistente não é.
 */
interface ArmazenamentoDeArquivos
{
    /**
     * Grava os bytes e devolve a chave final com tamanho e MIME medidos DEPOIS da escrita.
     *
     * Dois destinos, e a diferença é D8:
     *  - {@see NovoArquivo}    — arquivo novo; **o storage cunha** o nome opaco;
     *  - {@see ChaveDeArquivo} — arquivo existente; sobrescreve o conteúdo mantendo a chave.
     *
     * @throws FalhaDeArmazenamento se a escrita não completar (não engole erro em silêncio, ao
     *                              contrário do `file_put_contents` sem checagem de hoje)
     */
    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado;

    /**
     * Abre o conteúdo para leitura em streaming. Quem recebe o recurso é quem o fecha.
     *
     * @return resource
     *
     * @throws ArquivoNaoEncontrado
     * @throws FalhaDeArmazenamento
     */
    public function abrir(ChaveDeArquivo $chave): mixed;

    /**
     * Lê o conteúdo inteiro em memória.
     *
     * Conveniência para arquivo pequeno e conhecido — o HTML de peça é o caso real. Para qualquer
     * coisa que possa ser grande, use {@see abrir()}: carregar arquivo grande em memória viola
     * INV-4.
     *
     * @throws ArquivoNaoEncontrado
     * @throws FalhaDeArmazenamento
     */
    public function ler(ChaveDeArquivo $chave): string;

    /**
     * Presença do arquivo.
     *
     * **Lança em erro de I/O em vez de devolver false.** "Não consegui ler o diretório" não é
     * "o arquivo não existe", e confundir os dois é como uma rotina de limpeza apaga registro
     * válido. Ausência devolve false; impossibilidade de saber lança.
     *
     * @throws FalhaDeArmazenamento
     */
    public function existe(ChaveDeArquivo $chave): bool;

    /**
     * Remove. **Idempotente**: chave inexistente não é erro e não lança.
     *
     * @throws FalhaDeArmazenamento se o arquivo existe e mesmo assim não pôde ser removido
     */
    public function excluir(ChaveDeArquivo $chave): void;

    /**
     * Metadados, ou null quando não há arquivo na chave.
     *
     * `checksum` vem null na E2 por decisão (D6) — o campo existe para a E3 preencher.
     *
     * @throws FalhaDeArmazenamento
     */
    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo;
}
