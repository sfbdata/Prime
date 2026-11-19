<?php

declare(strict_types=1);

namespace App\Voto\Exception;

/**
 * A pessoa não pode ver a relação de votantes da assembleia, então também não vê o painel de unidades. O controller
 * responde 403.
 */
final class SemPermissaoParaVerAsUnidadesException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sem permissão para ver as unidades desta assembleia.');
    }
}
