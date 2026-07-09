<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

use App\Cobranca\Enum\StatusAcordo;

/**
 * Operação (romper, cancelar, marcar cumprido) tentada sobre um Acordo que não está `ativo`
 * (SPEC §12): só um acordo ativo transiciona de estado. Erro de entrada.
 */
final class AcordoNaoAtivoException extends \DomainException
{
    public function __construct(int $acordoId, StatusAcordo $statusAtual)
    {
        parent::__construct(sprintf(
            'Acordo %d não está ativo (status atual: %s) e não aceita esta operação.',
            $acordoId,
            $statusAtual->value,
        ));
    }
}
