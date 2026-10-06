<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * Pasta informada não existe no escritório atual — id inexistente ou de outro tenant. O controller
 * traduz em 404.
 */
final class PastaNaoEncontradaException extends \DomainException
{
    public function __construct(int $pastaId)
    {
        parent::__construct(sprintf('Pasta %d não encontrada neste escritório.', $pastaId));
    }
}
