<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento\Exception;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * A chave é válida, mas não há arquivo nela.
 *
 * É estado do mundo, não defeito: `abrir()`, `ler()` e `copiaGravavel()` lançam;
 * `existe()` devolve false e `metadados()` devolve null; `excluir()` é idempotente e não lança.
 * Traduzir isto em 404 é da camada de cima, nunca do storage.
 */
final class ArquivoNaoEncontrado extends \RuntimeException
{
    public static function para(ChaveDeArquivo $chave): self
    {
        return new self(sprintf('Arquivo não encontrado para a chave %s.', $chave->comoTexto()));
    }
}
