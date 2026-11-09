<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

use App\Ponto\Service\NomesDasSituacoesDaJustificativa;

/**
 * O colaborador tentou editar uma justificativa que já foi abonada ou recusada
 * (docs/specs/ponto-edicao-justificativa-analisada.md, R1). A mensagem é escrita para o usuário final:
 * o controller a repassa direto para o flash, inclusive na recusa rápida, que não lança.
 */
final class JustificativaJaAnalisadaException extends \DomainException
{
    // "Ou registrada como falta": a falta não justificada nasce abonada sem passar por gestor nenhum.
    // Nome da situação pelo ponto único do P40 (spec ponto-desenho-0811).
    public const MENSAGEM = NomesDasSituacoesDaJustificativa::VIGENTES['edicao.ja_analisada'];

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
