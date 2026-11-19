<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/**
 * O banco recusou excluir o cliente: outro registro ainda aponta para ele (hoje, a carteira de cobrança, a única chave
 * NO ACTION para `cliente`). A gravação não aconteceu; quem chama desfaz a transação e mostra a recusa.
 */
final class ClienteComVinculosException extends \DomainException
{
    public function __construct(int $clienteId, ?\Throwable $anterior = null)
    {
        parent::__construct(sprintf('Cliente %d tem vínculos que impedem a exclusão.', $clienteId), 0, $anterior);
    }
}
