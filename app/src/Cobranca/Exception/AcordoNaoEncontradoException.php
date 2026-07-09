<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Acordo informado não existe no escritório (tenant) atual — por id inexistente ou por pertencer
 * a outro escritório (guarda multi-tenant). Erro de entrada, traduzido pelo controller.
 */
final class AcordoNaoEncontradoException extends \DomainException
{
    public function __construct(int $acordoId)
    {
        parent::__construct(sprintf('Acordo %d não encontrado neste escritório.', $acordoId));
    }
}
