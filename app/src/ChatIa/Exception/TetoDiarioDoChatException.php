<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** A pessoa gastou o teto diário de tokens do Chat I.A (429). */
final class TetoDiarioDoChatException extends \DomainException
{
    public const CODIGO = 'teto_diario';
    public const MENSAGEM = 'Você chegou ao limite diário de uso do Chat I.A. Volta amanhã.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
