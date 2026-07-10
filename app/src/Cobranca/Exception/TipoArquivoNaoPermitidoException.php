<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * O tipo (MIME) do arquivo enviado não está na whitelist de documentos do Caso de Cobrança.
 * Erro de entrada, traduzido pelo controller.
 */
final class TipoArquivoNaoPermitidoException extends \DomainException
{
    public function __construct(string $mimeType)
    {
        parent::__construct(sprintf('Tipo de arquivo não permitido: %s.', $mimeType));
    }
}
