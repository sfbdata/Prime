<?php

declare(strict_types=1);

namespace App\Tenant\Exception;

/**
 * O COMMIT da purga falhou do ponto de vista da aplicação, e o banco não provou nem que confirmou
 * nem que desfez (E2.5, D18).
 *
 * O escritório PODE ter sido apagado. Nenhum arquivo foi tocado — apagar sem saber seria o mesmo
 * erro de INV-6 visto do outro lado —, e as chaves que a purga iria remover foram para o log, que
 * é o único rastro se o banco tiver confirmado. Quem receber isto confere o banco antes de repetir.
 */
final class PurgaComDestinoIncerto extends \RuntimeException
{
    public static function para(int $tenantId, string $xid, \Throwable $falha): self
    {
        return new self(
            sprintf(
                'O COMMIT da purga do escritório #%d falhou e o banco não confirmou o destino (xid %s). '
                . 'O escritório pode ter sido apagado; nenhum arquivo foi tocado e a lista do que '
                . 'seria removido está no log. Confira o banco antes de repetir.',
                $tenantId,
                $xid,
            ),
            0,
            $falha,
        );
    }
}
