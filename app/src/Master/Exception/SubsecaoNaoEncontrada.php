<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * A subseção pedida não existe ou não é da seccional da URL (spec master-oab-estados-que-faltam, "Remover").
 * O controller devolve 404, com a recusa no idioma da requisição ({@see RecusaDaSeccional}).
 */
final class SubsecaoNaoEncontrada extends RecusaDaSeccional
{
    public const RECUSA = 'Subseção não encontrada.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA => 'master.seccionais.recusa.subsecao_nao_encontrada',
    ];
}
