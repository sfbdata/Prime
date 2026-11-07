<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/** A versão pedida não é da pessoa nem do escritório da URL (ou não existe): o controller responde 404. */
final class VersaoDaJornadaNaoEncontradaException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Versão da jornada não encontrada.');
    }
}
