<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * A Carteira referenciada (por id) não existe no escritório (tenant) atual — seja por id
 * inexistente, seja por pertencer a outro escritório (guarda multi-tenant). É erro de entrada
 * do usuário (não de sistema): o controller a traduz em mensagem amigável no formulário.
 */
final class CarteiraNaoEncontradaException extends \DomainException
{
    public function __construct(int $carteiraId)
    {
        parent::__construct(sprintf('Carteira %d não encontrada neste escritório.', $carteiraId));
    }
}
