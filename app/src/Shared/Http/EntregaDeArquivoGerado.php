<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Armazenamento\ArquivoGeradoParaEntrega;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Entrega um arquivo GERADO pela aplicação (o .zip da aba Documentos) como download — e o apaga
 * depois de enviado.
 *
 * É o par da {@see EntregaDeArquivo}, com a política oposta e de propósito: lá o arquivo é
 * EMPRESTADO (o persistido, que ninguém apaga — INV-9); aqui é um {@see ArquivoGeradoParaEntrega},
 * que só pode ter nascido numa área temporária da aplicação e não existe para mais ninguém. O tipo
 * do parâmetro é o que separa as duas: não há como passar o caminho de um documento para cá.
 *
 * `deleteFileAfterSend` fica ligado: a `BinaryFileResponse` só abre o arquivo em `sendContent()`,
 * depois de o controller retornar, e o remove no `finally` do envio — inclusive no envio
 * interrompido. É o único lugar do sistema que liga essa flag
 * (`EntregaDeArquivoArquiteturaTest`).
 *
 * O envio é em blocos, sem carregar o arquivo em memória (INV-4): um .zip de 1 GB sai como os
 * documentos saem.
 */
final class EntregaDeArquivoGerado
{
    public function resposta(ArquivoGeradoParaEntrega $arquivo, string $nomeParaDownload, string $mimeType): BinaryFileResponse
    {
        $resposta = new BinaryFileResponse($arquivo->caminho());
        $resposta->headers->set('Content-Type', $mimeType);
        $resposta->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nomeParaDownload);
        $resposta->deleteFileAfterSend(true);

        return $resposta;
    }
}
