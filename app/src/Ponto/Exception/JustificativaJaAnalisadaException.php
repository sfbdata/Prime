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
    public const MENSAGEM = 'Esta justificativa já foi analisada (abonada ou rejeitada) e não pode mais ser '
        . 'alterada. Para corrigi-la, peça ao administrador para revertê-la para pendente.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
