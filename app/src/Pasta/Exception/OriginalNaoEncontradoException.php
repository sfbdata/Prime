<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Ao copiar (D6), o documento de origem tem linha no banco mas o arquivo não está no
 * armazenamento. Estende `InvalidArgumentException` para cair no 422 das recusas de lote; a
 * mensagem leva o nome e a garantia: nada foi copiado.
 */
final class OriginalNaoEncontradoException extends \InvalidArgumentException
{
    public static function para(string $nomeOriginal): self
    {
        return new self(sprintf('O arquivo original de «%s» não foi encontrado; nada foi copiado.', $nomeOriginal));
    }
}
