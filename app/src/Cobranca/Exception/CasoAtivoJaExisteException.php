<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de abrir um segundo caso ativo para o mesmo objeto quando a carteira opera em modo
 * "uma cobrança ativa por objeto" (modo A, SPEC §6, invariável 6). Enquanto houver caso ativo,
 * novas pendências entram nele; um novo caso só nasce após o anterior ser encerrado.
 */
final class CasoAtivoJaExisteException extends \DomainException
{
    public function __construct(int $objetoId)
    {
        parent::__construct(sprintf(
            'Já existe um caso de cobrança ativo para o objeto %d (modo de uma cobrança ativa por objeto).',
            $objetoId,
        ));
    }
}
