<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * Análise informada não existe neste escritório/pasta — id inexistente, de outro tenant ou de outra
 * pasta (guarda multi-tenant + IDOR). O controller traduz em 404, nunca 403: um 403 confirmaria que
 * o id existe.
 */
final class AnaliseNaoEncontradaException extends \DomainException
{
    public function __construct(int $analiseId)
    {
        parent::__construct(sprintf('Análise %d não encontrada nesta pasta.', $analiseId));
    }
}
