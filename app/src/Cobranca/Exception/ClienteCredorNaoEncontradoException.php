<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Cliente credor informado ao criar uma Carteira não existe no escritório (tenant) atual — seja por
 * id inexistente, seja por pertencer a outro escritório (guarda multi-tenant). É erro de entrada do
 * usuário (não de sistema): o controller a traduz em mensagem amigável no formulário.
 */
final class ClienteCredorNaoEncontradoException extends \DomainException
{
    public function __construct(int $clienteId)
    {
        parent::__construct(sprintf('Cliente credor %d não encontrado neste escritório.', $clienteId));
    }
}
