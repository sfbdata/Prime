<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O destino da réplica dos arquivos (spec P2 §4) — **escrita, consulta e download conferido**.
 *
 * Não há verbo de exclusão, e isso é a primeira barreira da regra 0 da P2 ("nenhuma exclusão
 * remota"): não existe o que chamar. `EspelhoRemotoArquiteturaTest` trava a lista de métodos.
 *
 * Endereçado por {@see ChaveDeArquivo}, não por string: quem chama não escolhe a chave remota — ela
 * sai de {@see LayoutDeChaveRemota}, com o tenant do escopo. Um chamador não consegue, nem por
 * engano, gravar fora de `core/` nem no prefixo de outro escritório.
 *
 * Conteúdo sai daqui só por {@see baixar()}, e só para a leitura de recuperação (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md`, D1 a D3): o disco local continua sendo a origem de
 * leitura, e o espelho só é lido quando o disco não tem o arquivo e a outbox prova um envio
 * `verificado` daquela chave. Não há `abrir()` nem `ler()`: o download vai inteiro para um destino
 * local e só conta como feito com tamanho e SHA-256 conferidos.
 */
interface EspelhoRemoto
{
    /**
     * Grava (ou regrava) o conteúdo na chave remota e confere o que ficou gravado.
     *
     * @param resource $conteudo stream legível, posicionado no início
     * @param string   $sha256   hex de 64 caracteres do conteúdo, medido pelo chamador no disco local
     *
     * @throws FalhaNoEspelhoRemoto quando o destino recusa, não responde, ou o que ficou gravado não confere
     */
    public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void;

    /**
     * Metadados do objeto remoto, ou null quando ele não existe.
     *
     * @throws FalhaNoEspelhoRemoto quando não foi possível perguntar (ausência ≠ pane, como D10)
     */
    public function consultar(ChaveDeArquivo $chave): ?MetadadosRemotos;

    /** @throws FalhaNoEspelhoRemoto */
    public function existe(ChaveDeArquivo $chave): bool;

    /**
     * Baixa o objeto remoto para `$destino`, em blocos, sem carregar o conteúdo inteiro em memória, e
     * confere o que chegou.
     *
     * Quem chama trata o destino como descartável: numa falha ele pode ter recebido parte dos bytes,
     * e nada do que foi escrito nele pode ser entregue.
     *
     * @param resource $destino         stream gravável
     * @param string   $sha256Esperado  hex de 64 caracteres, o da outbox `verificado`
     *
     * @throws FalhaNoEspelhoRemoto quando o objeto não existe, o destino não responde, ou o que chegou
     *                              não tem o tamanho ou o SHA-256 esperados
     */
    public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void;
}
