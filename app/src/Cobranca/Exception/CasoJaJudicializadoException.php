<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de judicializar um Caso de Cobrança que já está judicializado (SPEC §16). A
 * judicialização é uma transição única de fase (extrajudicial → judicializada); revincular a pasta
 * ou rejudicializar não é operação do MVP.
 */
final class CasoJaJudicializadoException extends \DomainException
{
    public function __construct(int $casoId)
    {
        parent::__construct(sprintf('Caso de cobrança %d já está judicializado.', $casoId));
    }
}
