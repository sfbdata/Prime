<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Vínculo informado ao encerrar não existe no escritório (tenant) atual — seja por id inexistente,
 * seja por pertencer a outro escritório (guarda multi-tenant, invariável 24). É erro de entrada do
 * usuário (não de sistema): o controller a traduz em mensagem amigável.
 */
final class VinculoNaoEncontradoException extends \DomainException
{
    public function __construct(int $vinculoId)
    {
        parent::__construct(sprintf('Vínculo %d não encontrado neste escritório.', $vinculoId));
    }
}
