<?php

declare(strict_types=1);

namespace App\Dashboard\Exception;

/**
 * O id pedido não é colaborador ativo do escritório corrente (outro escritório, desligado ou
 * inexistente). Vira 404 no controller: o painel não confirma que a pessoa existe em outro lugar.
 */
final class ColaboradorForaDoEscritorioException extends \DomainException
{
}
