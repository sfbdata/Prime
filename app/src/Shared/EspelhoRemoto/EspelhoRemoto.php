<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O destino da réplica dos arquivos (spec P2 §4) — **só escrita e consulta**.
 *
 * Não há verbo de exclusão, e isso é a primeira barreira da regra 0 da P2 ("nenhuma exclusão
 * remota"): não existe o que chamar. `EspelhoRemotoArquiteturaTest` trava a lista de métodos.
 *
 * Endereçado por {@see ChaveDeArquivo}, não por string: quem chama não escolhe a chave remota — ela
 * sai de {@see LayoutDeChaveRemota}, com o tenant do escopo. Um chamador não consegue, nem por
 * engano, gravar fora de `core/` nem no prefixo de outro escritório.
 *
 * Na P2 ninguém lê CONTEÚDO daqui: o disco local é a única origem de leitura (DP-4). Por isso não
 * há `abrir()` nem `ler()`.
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
}
