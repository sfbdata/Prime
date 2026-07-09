<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Obrigação informada não existe no escritório (tenant) atual — por id inexistente ou por pertencer
 * a outro escritório (guarda multi-tenant). Erro de entrada, traduzido pelo controller.
 */
final class ObrigacaoNaoEncontradaException extends \DomainException
{
    public function __construct(int $obrigacaoId)
    {
        parent::__construct(sprintf('Obrigação %d não encontrada neste escritório.', $obrigacaoId));
    }
}
