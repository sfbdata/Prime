<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** Anexo recusado: tipo, tamanho, quantidade, posse ou conteúdo (422). */
final class AnexoDoChatInvalidoException extends \DomainException
{
    public const CODIGO = 'anexo_invalido';
    public const MENSAGEM = 'Anexo inválido.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
