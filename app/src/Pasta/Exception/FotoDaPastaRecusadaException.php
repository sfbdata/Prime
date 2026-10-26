<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * A foto da pasta não muda por este pedido: a pasta está excluída (lápide, somente leitura) ou já tem cliente
 * cadastrado (a foto nova vai para o cliente, pela rota da foto do cliente). 403.
 */
final class FotoDaPastaRecusadaException extends \DomainException
{
}
