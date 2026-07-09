<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Documento informado não existe no escritório (tenant) atual — por id inexistente ou por pertencer a
 * outro escritório (guarda multi-tenant). Erro de entrada, traduzido pelo controller.
 */
final class DocumentoNaoEncontradoException extends \DomainException
{
    public function __construct(int $documentoId)
    {
        parent::__construct(sprintf('Documento %d não encontrado neste escritório.', $documentoId));
    }
}
