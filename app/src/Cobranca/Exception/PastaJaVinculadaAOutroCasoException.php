<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de vincular (modo "vincular pasta existente" da judicialização) uma Pasta que já está
 * ligada a OUTRO Caso de Cobrança. A ligação Caso→Pasta é individual — uma pasta judicial não pode
 * responder por dois casos ao mesmo tempo, senão a tela da pasta (`unidadeCobradaDaPasta`) fica
 * ambígua sobre qual unidade/pessoa ela representa.
 */
final class PastaJaVinculadaAOutroCasoException extends \DomainException
{
    public function __construct(int $pastaId, int $outroCasoId)
    {
        parent::__construct(sprintf('Esta pasta já está vinculada a outro caso de cobrança (#%d).', $outroCasoId));
    }
}
