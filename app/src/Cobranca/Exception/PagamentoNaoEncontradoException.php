<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Pagamento informado não existe no escritório (tenant) atual — por id inexistente ou por pertencer
 * a outro escritório (guarda multi-tenant). Erro de entrada, traduzido pelo controller.
 */
final class PagamentoNaoEncontradoException extends \DomainException
{
    public function __construct(int $pagamentoId)
    {
        parent::__construct(sprintf('Pagamento %d não encontrado neste escritório.', $pagamentoId));
    }
}
