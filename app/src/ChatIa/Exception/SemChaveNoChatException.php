<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** O provedor não está configurado nesta instalação (sem chave): 503. */
final class SemChaveNoChatException extends \DomainException
{
    public const CODIGO = 'sem_chave';
    public const MENSAGEM = 'A IA ainda não está conectada.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
