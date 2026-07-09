<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de encerrar um vínculo que já possui data de encerramento. Encerrar de novo sobrescreveria
 * o encerramento anterior e apagaria histórico — o que é proibido (invariável 11). É erro de fluxo do
 * usuário: o controller a traduz em mensagem amigável.
 */
final class VinculoJaEncerradoException extends \DomainException
{
    public function __construct(int $vinculoId)
    {
        parent::__construct(sprintf('Vínculo %d já está encerrado.', $vinculoId));
    }
}
