<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Quantas páginas tem um PDF que está num caminho LOCAL (D1, DOC-65).
 *
 * Interface porque a implementação real chama um binário externo (Ghostscript): o UseCase de
 * upload e o comando de preenchimento são testados com um dublê, e a implementação é provada
 * sozinha, contra o binário.
 */
interface ContadorDePaginasDePdf
{
    /**
     * Páginas do PDF, ou NULL quando não dá para contar — arquivo ilegível, PDF corrompido,
     * binário ausente, timeout. NUNCA lança: a contagem é opcional, e o upload que a pede não pode
     * virar 500 por causa dela (a mesma política do sha256 pós-compressão).
     */
    public function contar(string $caminhoLocal): ?int;
}
