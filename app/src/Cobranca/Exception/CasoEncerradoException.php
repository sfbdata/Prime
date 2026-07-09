<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de lançar uma nova obrigação em um Caso de Cobrança já encerrado (SPEC §17): caso
 * encerrado não recebe novas obrigações — uma nova inadimplência gera um novo caso.
 */
final class CasoEncerradoException extends \DomainException
{
    public function __construct(int $casoId)
    {
        parent::__construct(sprintf(
            'Caso de cobrança %d está encerrado e não recebe novas obrigações.',
            $casoId,
        ));
    }
}
