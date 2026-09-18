<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Entrega um arquivo persistido como resposta HTTP, endereçado por CHAVE (E2.3, D11).
 *
 * ```
 * Controller → ChaveDeArquivo → EntregaDeArquivo → MaterializadorDeArquivo → backend
 * ```
 *
 * O controller decide **quem pode** baixar, **qual** arquivo (a chave, montada pela fábrica do
 * domínio), **com que nome** e **se abre ou baixa**. Não sabe onde o arquivo mora: nem raiz
 * física, nem `public/uploads`, nem resolvedor. Isso é daqui para baixo.
 *
 * Esta classe mora na borda HTTP, fora de `App\Shared\Armazenamento`, porque depende de
 * HttpFoundation — e o núcleo não pode (INV-8).
 *
 * ## O que ela preserva do `servir()` antigo, bit a bit
 *
 * A mesma `BinaryFileResponse` sobre o mesmo caminho, com a mesma chamada de
 * `setContentDisposition()`. Daí vêm de graça, e idênticos: `Content-Type` (adivinhado do
 * arquivo), `Accept-Ranges`, resposta 206 a `Range`, `Last-Modified`, o fallback ASCII do nome e
 * o envio em blocos, sem carregar o arquivo em memória (INV-4). `EntregaDeArquivoTest` compara
 * os cabeçalhos das duas implementações lado a lado.
 *
 * ## INV-9 — o arquivo é emprestado, e a resposta não pode apagá-lo
 *
 * O materializador devolve um {@see ArquivoEmprestado}: o caminho do arquivo de produção. A
 * `BinaryFileResponse` só abre esse arquivo em `sendContent()`, **depois** de o controller
 * retornar, e apagaria se `deleteFileAfterSend` estivesse ligado. Por isso esta classe **nunca**
 * chama `deleteFileAfterSend()` — e o tipo recebido não oferece nada que apague.
 *
 * ## D10 — erro na entrega
 *
 *  - arquivo ausente → **404**, e só isso vira 404 aqui;
 *  - {@see FalhaDeArmazenamento} (permissão, I/O, backend fora) **passa direto**. Não há catch
 *    amplo: "não consegui ler o storage" não é "o arquivo não existe";
 *  - chave inválida vinda da URL é da rota — é ela que monta a chave e responde 404 à recusa.
 *
 * ## O que ainda não está aqui
 *
 * Redirect, URL assinada e streaming de backend remoto são decisão da E4. Hoje o único
 * materializador é o disco local, e esta classe só sabe entregar um arquivo emprestado.
 */
final class EntregaDeArquivo
{
    public function __construct(
        private readonly MaterializadorDeArquivo $materializador,
    ) {
    }

    /**
     * @throws NotFoundHttpException quando não há arquivo na chave
     * @throws FalhaDeArmazenamento  quando o storage não consegue responder — nunca vira 404
     */
    public function resposta(ChaveDeArquivo $chave, string $nomeParaDownload, bool $inline): Response
    {
        try {
            $arquivo = $this->materializador->paraLeitura($chave);
        } catch (ArquivoNaoEncontrado $e) {
            throw new NotFoundHttpException('Arquivo não encontrado.', $e);
        }

        return $this->deEmprestado($arquivo, $nomeParaDownload, $inline);
    }

    private function deEmprestado(ArquivoEmprestado $arquivo, string $nomeParaDownload, bool $inline): BinaryFileResponse
    {
        $resposta = new BinaryFileResponse($arquivo->caminho());
        $resposta->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $nomeParaDownload,
        );

        return $resposta;
    }
}
