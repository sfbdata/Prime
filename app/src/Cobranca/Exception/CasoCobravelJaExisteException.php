<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de abrir um segundo caso COBRÁVEL para o mesmo objeto quando a carteira opera em modo
 * "uma cobrança viva por objeto" (modo A, SPEC §6, invariável 6). Enquanto houver caso não encerrado
 * — `ativo` **ou** `judicializado` —, novas pendências entram nele; um novo caso só nasce após o
 * anterior ser encerrado.
 *
 * ⚠️ Chamava-se `CasoAtivoJaExisteException` e a guarda contava só o `ativo`, o que deixava um objeto
 * com caso judicializado receber um segundo caso em silêncio. Ver
 * `docs/specs/cobranca-importe-enxerga-caso-judicializado.md`.
 */
final class CasoCobravelJaExisteException extends \DomainException
{
    public function __construct(int $objetoId)
    {
        parent::__construct(sprintf(
            'Já existe um caso de cobrança não encerrado para o objeto %d (modo de uma cobrança viva por objeto).',
            $objetoId,
        ));
    }
}
