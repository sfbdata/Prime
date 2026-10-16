<?php

declare(strict_types=1);

namespace App\Central\Exception;

/**
 * Conversa inexistente, de outro escritório ou de que o usuário não participa — os três casos dão a mesma
 * resposta (404), para não confirmar que a conversa existe.
 */
final class ConversaNaoEncontradaException extends \DomainException
{
    public function __construct(int $conversaId)
    {
        parent::__construct(sprintf('Conversa %d não encontrada.', $conversaId));
    }
}
