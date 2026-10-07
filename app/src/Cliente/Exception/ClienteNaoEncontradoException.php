<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/**
 * Cliente inexistente OU de outro escritório — as duas situações são a mesma
 * para quem chama (404): distinguir confirmaria que o id existe.
 */
final class ClienteNaoEncontradoException extends \DomainException
{
    public function __construct(int $clienteId)
    {
        parent::__construct(sprintf('Cliente %d não encontrado.', $clienteId));
    }
}
