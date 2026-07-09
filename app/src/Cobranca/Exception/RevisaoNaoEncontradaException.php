<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Revisão de pessoa cobrada informada não existe no escritório (tenant) atual, seja por id
 * inexistente, seja por pertencer a outro escritório (guarda multi-tenant, invariável 1). Erro de
 * entrada — o controller a traduz em mensagem amigável.
 */
final class RevisaoNaoEncontradaException extends \DomainException
{
    public function __construct(int $revisaoId)
    {
        parent::__construct(sprintf('Revisão %d não encontrada neste escritório.', $revisaoId));
    }
}
