<?php

declare(strict_types=1);

namespace App\Comercial\Exception;

/** O pedido do Master não vale para esta automação (chave desconhecida, obrigatória ou atraso fora das opções). */
final class AutomacaoRecusada extends \DomainException
{
}
