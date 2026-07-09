<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Caso de Cobrança informado não existe no escritório (tenant) atual — por id inexistente ou por
 * pertencer a outro escritório (guarda multi-tenant). Erro de entrada, traduzido pelo controller.
 */
final class CasoNaoEncontradoException extends \DomainException
{
    public function __construct(int $casoId)
    {
        parent::__construct(sprintf('Caso de cobrança %d não encontrado neste escritório.', $casoId));
    }
}
