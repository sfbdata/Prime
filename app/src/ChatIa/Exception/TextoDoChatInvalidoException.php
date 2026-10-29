<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** Texto da pergunta vazio ou acima do limite (422). */
final class TextoDoChatInvalidoException extends \DomainException
{
    public const CODIGO = 'texto_invalido';
    public const MENSAGEM = 'Escreva uma mensagem de 1 a 8000 caracteres.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
