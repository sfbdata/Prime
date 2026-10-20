<?php

declare(strict_types=1);

namespace App\Central\Exception;

/**
 * Pasta ou item que não existe, de outra pasta, de outro escritório ou que a pessoa não vê: os casos dão a mesma
 * resposta (404), para não confirmar que existe.
 */
final class ItemEncaminhadoNaoEncontradoException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Item não encontrado.');
    }
}
