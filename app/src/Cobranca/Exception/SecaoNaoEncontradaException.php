<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Seção de documentos informada não existe no escritório (tenant) atual, não pertence ao caso indicado,
 * ou pertence a outro escritório (guarda multi-tenant). Erro de entrada, traduzido pelo controller.
 */
final class SecaoNaoEncontradaException extends \DomainException
{
    public function __construct(int $secaoId)
    {
        parent::__construct(sprintf('Seção de documentos %d não encontrada neste escritório.', $secaoId));
    }
}
