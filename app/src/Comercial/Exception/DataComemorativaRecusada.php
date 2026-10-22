<?php

declare(strict_types=1);

namespace App\Comercial\Exception;

/** O pedido do Master não vale para esta data comemorativa (chave desconhecida, antecedência ou público fora da lista). */
final class DataComemorativaRecusada extends \DomainException
{
}
