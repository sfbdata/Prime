<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** Conversa que não existe, é de outro escritório ou é de outra pessoa: o MESMO 404, sem confirmar que existe. */
final class ConversaDeChatNaoEncontradaException extends \DomainException
{
    public const CODIGO = 'conversa_nao_encontrada';
    public const MENSAGEM = 'Conversa não encontrada.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
