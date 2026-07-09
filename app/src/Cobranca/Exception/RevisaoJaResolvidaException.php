<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de resolver uma revisão de pessoa cobrada que já está resolvida (SPEC §8): a resolução é
 * única — depois dela o mesmo evento não deve continuar gerando alerta nem ser reprocessado.
 */
final class RevisaoJaResolvidaException extends \DomainException
{
    public function __construct(int $revisaoId)
    {
        parent::__construct(sprintf('Revisão %d já está resolvida.', $revisaoId));
    }
}
