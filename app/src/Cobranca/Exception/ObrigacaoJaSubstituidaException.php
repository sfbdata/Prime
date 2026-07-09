<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de substituir por acordo uma obrigação que JÁ está substituída por um acordo vigente
 * (SPEC §12): uma obrigação não pode ser substituída duas vezes ao mesmo tempo. Erro de entrada.
 */
final class ObrigacaoJaSubstituidaException extends \DomainException
{
    public function __construct(int $obrigacaoId)
    {
        parent::__construct(sprintf(
            'Obrigação %d já foi substituída por um acordo vigente.',
            $obrigacaoId,
        ));
    }
}
