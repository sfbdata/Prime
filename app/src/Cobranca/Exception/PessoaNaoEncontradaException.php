<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Pessoa informada não existe no escritório (tenant) atual — seja por id inexistente, seja por
 * pertencer a outro escritório (guarda multi-tenant). É erro de entrada do usuário (não de
 * sistema): o controller a traduz em mensagem amigável.
 */
final class PessoaNaoEncontradaException extends \DomainException
{
    public function __construct(int $pessoaId)
    {
        parent::__construct(sprintf('Pessoa %d não encontrada neste escritório.', $pessoaId));
    }
}
