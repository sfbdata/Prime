<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Objeto de cobrança informado não existe no escritório (tenant) atual — seja por id inexistente,
 * seja por pertencer a outro escritório (guarda multi-tenant). É erro de entrada do usuário (não de
 * sistema): o controller a traduz em mensagem amigável.
 */
final class ObjetoNaoEncontradoException extends \DomainException
{
    public function __construct(int $objetoId)
    {
        parent::__construct(sprintf('Objeto de cobrança %d não encontrado neste escritório.', $objetoId));
    }
}
