<?php

declare(strict_types=1);

namespace App\Profile\Exception;

/** O idioma pedido não existe ou ainda não foi liberado (spec idioma-do-sistema). O controller responde 422. */
final class IdiomaIndisponivelException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Este idioma ainda não está disponível.');
    }
}
