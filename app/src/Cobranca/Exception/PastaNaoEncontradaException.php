<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Pasta informada na judicialização não existe no escritório (tenant) atual — seja por id
 * inexistente, seja por pertencer a OUTRO escritório (guarda multi-tenant, invariável 1; SPEC §16
 * exige respeitar o tenant do módulo de destino). A mensagem não distingue os dois casos para não
 * vazar a existência de pastas de outros tenants. Erro de entrada — o controller a traduz.
 */
final class PastaNaoEncontradaException extends \DomainException
{
    public function __construct(int $pastaId)
    {
        parent::__construct(sprintf('Pasta %d não encontrada neste escritório.', $pastaId));
    }
}
