<?php

declare(strict_types=1);

namespace App\Shared\Service;

interface CompressorArquivoInterface
{
    /**
     * Comprime o arquivo no caminho informado, in-place.
     *
     * Best-effort: nunca lança por falha de compressão. Só substitui o arquivo
     * original se a versão comprimida for menor; caso contrário mantém o original.
     * Tipos não suportados são no-op.
     */
    public function comprimir(string $caminhoCompleto, string $mimeType): ResultadoCompressao;

    /**
     * Este MIME tem compressão implementada? (E2.6C)
     *
     * Existe para quem trabalha POR CHAVE: sem isto, o serviço copiava o arquivo inteiro para o
     * temporário só para o compressor responder "não trato" — um DOCX de 10 MB ia e voltava do
     * `/tmp` a cada upload com "reduzir tamanho" marcado.
     */
    public function trata(string $mimeType): bool;

    /**
     * Indica se o PDF contém assinatura digital (marcadores /ByteRange e /Sig).
     */
    public function pdfEstaAssinado(string $caminhoCompleto): bool;
}
