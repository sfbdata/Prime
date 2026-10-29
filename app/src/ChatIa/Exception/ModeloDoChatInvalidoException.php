<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** Modelo fora de ModeloDeIa (422). */
final class ModeloDoChatInvalidoException extends \DomainException
{
    public const CODIGO = 'modelo_invalido';
    public const MENSAGEM = 'Modelo de IA inválido.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
