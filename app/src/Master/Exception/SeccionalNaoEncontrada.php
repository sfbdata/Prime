<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * A UF é uma das 27, mas a linha da seccional não existe (banco sem a carga da migration
 * Version20261020148400). O controller devolve 404, com a recusa no idioma da requisição ({@see RecusaDaSeccional}).
 */
final class SeccionalNaoEncontrada extends RecusaDaSeccional
{
    public const RECUSA = 'Seccional não encontrada.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA => 'master.seccionais.nao_encontrada',
    ];
}
