<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/** O Master pediu para alterar a IA de um escritório que não existe, foi suspenso ou excluído (404). */
final class EscritorioNaoEncontradoException extends \DomainException
{
    public function __construct(public readonly int $tenantId)
    {
        parent::__construct('Escritório não encontrado.');
    }
}
