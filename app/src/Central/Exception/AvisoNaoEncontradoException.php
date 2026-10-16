<?php

declare(strict_types=1);

namespace App\Central\Exception;

/** Aviso inexistente, de outra pessoa, de outro escritório ou fora da Caixa: a mesma resposta (404), sem confirmar nada. */
final class AvisoNaoEncontradoException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Aviso não encontrado.');
    }
}
