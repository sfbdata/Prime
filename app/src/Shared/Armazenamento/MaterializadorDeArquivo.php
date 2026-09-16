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
 * ## Por que só a leitura, por enquanto
 *
 * A E2.3 precisa de `paraLeitura()`: a camada de entrega HTTP monta a resposta sobre um caminho
 * real, e no disco local esse caminho é o próprio arquivo, em cópia zero. A cópia **gravável** —
 * o `ArquivoTemporarioPossuido` que o compressor vai usar — entra na E2.6, junto dos seus
 * consumidores e depois dos testes de modo de falha que D4 exige antes. Declarar o método agora
 * seria contrato sem consumidor nem prova.
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
}
