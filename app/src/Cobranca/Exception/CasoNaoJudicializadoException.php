<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de cancelar a judicialização de um Caso de Cobrança que não está judicializado
 * (nunca foi, ou já foi encerrado). Só um caso com `status = Judicializado` pode ser cancelado.
 */
final class CasoNaoJudicializadoException extends \DomainException
{
    public function __construct(int $casoId)
    {
        parent::__construct(sprintf('Caso de cobrança %d não está judicializado.', $casoId));
    }
}
