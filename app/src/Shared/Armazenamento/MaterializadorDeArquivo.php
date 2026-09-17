<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * Caminho local real para quem não consegue trabalhar com stream (D9).
 *
 * Abstração **segregada** do núcleo de propósito: um backend remoto responde isto de forma
 * completamente diferente (baixando para um temporário possuído, ou nem respondendo), e o núcleo
 * de seis verbos não pode crescer por causa disso.
 *
 * ## Duas formas, dois tipos
 *
 *  - `paraLeitura()` (E2.3) — a entrega HTTP monta a resposta sobre um caminho real, e no disco
 *    local esse caminho é o próprio arquivo, em cópia zero. Emprestado: ninguém apaga;
 *  - `copiaGravavel()` (E2.6A) — quem precisa REESCREVER o conteúdo (o compressor) recebe uma cópia
 *    possuída num diretório privado, fora do volume (D29). Escrever nela não toca o persistido; a
 *    volta para o storage é um `gravar()` explícito na mesma chave, feito por quem decidiu que o
 *    resultado vale (`CompressaoDeArquivoArmazenado`).
 *
 * ## A distinção que ninguém pode apagar (D10)
 *
 *  - {@see ArquivoNaoEncontrado} — o arquivo não está lá. A rota pode responder 404.
 *  - {@see FalhaDeArmazenamento} — não foi possível determinar ou ler (permissão, I/O, backend
 *    fora). A rota **não** pode transformar isto em 404: esconderia uma pane como "arquivo
 *    sumiu", e é assim que rotina de limpeza apaga registro válido.
 */
interface MaterializadorDeArquivo
{
    /**
     * Caminho local para LEITURA, sem posse: nada que receba isto pode apagar o arquivo (INV-9).
     *
     * @throws ArquivoNaoEncontrado quando não há arquivo na chave
     * @throws FalhaDeArmazenamento quando não dá para saber, ou o arquivo existe e não é legível
     */
    public function paraLeitura(ChaveDeArquivo $chave): ArquivoEmprestado;

    /**
     * Cópia GRAVÁVEL e possuída, num temporário privado fora do volume (D9, D29). Quem recebe libera
     * em `finally`. Falha no meio (disco cheio, leitura interrompida) lança e não deixa cópia parcial;
     * o original nunca é tocado (INV-9).
     *
     * @throws ArquivoNaoEncontrado quando não há arquivo na chave
     * @throws FalhaDeArmazenamento quando não dá para saber, ler ou copiar inteiro
     */
    public function copiaGravavel(ChaveDeArquivo $chave): ArquivoTemporarioPossuido;
}
