<?php

declare(strict_types=1);

namespace App\Voto\Exception;

/**
 * A assembleia, a pauta ou a unidade pedida não existe no escritório da sessão (guarda de posse). O controller
 * responde 404: o id de outro escritório tem a mesma resposta que um id inexistente.
 */
final class AssembleiaNaoEncontradaException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Assembleia não encontrada.');
    }
}
