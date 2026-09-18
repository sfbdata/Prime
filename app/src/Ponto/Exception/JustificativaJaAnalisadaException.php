<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * O colaborador tentou editar uma justificativa que já foi abonada ou rejeitada
 * (docs/specs/ponto-edicao-justificativa-analisada.md, R1). A mensagem é escrita para o usuário final:
 * o controller a repassa direto para o flash — inclusive na recusa rápida, que não lança.
 */
final class JustificativaJaAnalisadaException extends \DomainException
{
    // "Ou registrada como falta": a falta não justificada nasce abonada sem passar por gestor nenhum.
    public const MENSAGEM = 'Esta justificativa não está mais pendente — já foi analisada pelo gestor ou '
        . 'registrada como falta — e não pode ser alterada. Para corrigi-la, peça ao administrador para '
        . 'revertê-la para pendente.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
