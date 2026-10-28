<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** O contexto de pasta ou o anexo exige o aceite do escritório, que não foi dado (409). */
final class SemAceiteNoChatException extends \DomainException
{
    public const CODIGO = 'sem_aceite';
    public const MENSAGEM = 'O escritório ainda não deu o aceite para enviar dados à IA.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
