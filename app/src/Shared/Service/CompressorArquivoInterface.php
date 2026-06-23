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
     * Indica se o PDF contém assinatura digital (marcadores /ByteRange e /Sig).
     */
    public function pdfEstaAssinado(string $caminhoCompleto): bool;
}
