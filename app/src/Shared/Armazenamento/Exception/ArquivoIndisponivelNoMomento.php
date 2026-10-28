<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento\Exception;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * O arquivo existe, mas não pôde ser obtido agora: a cópia local foi liberada (ou sumiu) e a cópia
 * remota não respondeu, não conferiu ou não coube no cache de leitura.
 *
 * Não é {@see ArquivoNaoEncontrado}: há prova de que o arquivo existe (o envio `verificado` da outbox),
 * então a borda HTTP responde 503, nunca 404. É uma {@see FalhaDeArmazenamento} para quem já captura a
 * base continuar tratando como pane, sem mudar de comportamento.
 *
 * A mensagem leva só categoria e escritório, nunca o nome do arquivo (nos legados ele tem nome de pessoa).
 */
final class ArquivoIndisponivelNoMomento extends FalhaDeArmazenamento
{
    public static function para(ChaveDeArquivo $chave, ?\Throwable $causa = null): self
    {
        return new self(
            sprintf('Arquivo temporariamente indisponível (categoria=%s, escritório=%s).', $chave->categoria->value, $chave->escopo->comoTexto()),
            0,
            $causa,
        );
    }
}
