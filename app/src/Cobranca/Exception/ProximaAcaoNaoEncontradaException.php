<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Próxima ação informada não existe no escritório (tenant) atual, seja por id inexistente, seja por
 * pertencer a outro escritório (guarda multi-tenant, invariável 1), ou já não está pendente. Erro de
 * entrada — o controller a traduz em mensagem amigável.
 */
final class ProximaAcaoNaoEncontradaException extends \DomainException
{
    public function __construct(int $acaoId)
    {
        parent::__construct(sprintf('Próxima ação %d não encontrada neste escritório.', $acaoId));
    }
}
