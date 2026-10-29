<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** Quem clicou não pode fazer o que a proposta pede (a permissão é conferida NO CLIQUE, não na hora da proposta): 403. */
final class SemPermissaoParaPropostaException extends \DomainException
{
    public const CODIGO = 'sem_permissao';

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
