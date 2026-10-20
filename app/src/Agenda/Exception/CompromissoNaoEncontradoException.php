<?php

declare(strict_types=1);

namespace App\Agenda\Exception;

/** Compromisso inexistente, de outro escritório ou invisível para quem pede: responde 404. */
final class CompromissoNaoEncontradoException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Compromisso não encontrado.');
    }
}
