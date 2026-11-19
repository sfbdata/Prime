<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/**
 * Documento do cadastro inexistente OU de outro escritório: as duas situações são a mesma para quem chama (404).
 */
final class DocumentoDoClienteNaoEncontradoException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Documento do cliente não encontrado.');
    }
}
