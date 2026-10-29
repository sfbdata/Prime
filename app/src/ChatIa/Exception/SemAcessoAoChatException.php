<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** Sem vínculo ativo no escritório ou sem a permissão do módulo da I.A (403). */
final class SemAcessoAoChatException extends \DomainException
{
    public const CODIGO = 'sem_acesso';
    public const MENSAGEM = 'Você não tem acesso ao Chat I.A neste escritório.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
