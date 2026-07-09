<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Pessoa informada ao criar um vínculo não existe no escritório (tenant) atual — seja por id
 * inexistente, seja por pertencer a outro escritório (guarda multi-tenant, invariável 24). É erro
 * de entrada do usuário (não de sistema): o controller a traduz em mensagem amigável no formulário.
 */
final class PessoaNaoEncontradaException extends \DomainException
{
    public function __construct(int $pessoaId)
    {
        parent::__construct(sprintf('Pessoa %d não encontrada neste escritório.', $pessoaId));
    }
}
