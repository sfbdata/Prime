<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de encerrar um vínculo que já está encerrado (dataFim preenchida). O histórico temporal
 * não é reescrito (invariável 11): reencerrar é rejeitado. É erro de entrada do usuário (não de
 * sistema): o controller a traduz em mensagem amigável.
 */
final class VinculoJaEncerradoException extends \DomainException
{
    public function __construct(int $vinculoId)
    {
        parent::__construct(sprintf('O vínculo %d já está encerrado.', $vinculoId));
    }
}
