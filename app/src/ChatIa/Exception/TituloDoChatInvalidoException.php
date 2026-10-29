<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** Título acima do limite ou dado inválido no PATCH da conversa (422). */
final class TituloDoChatInvalidoException extends \DomainException
{
    public const CODIGO = 'titulo_invalido';
    public const MENSAGEM = 'O título deve ter até 120 caracteres.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
