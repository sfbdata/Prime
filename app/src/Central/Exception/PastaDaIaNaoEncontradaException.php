<?php

declare(strict_types=1);

namespace App\Central\Exception;

/**
 * A pasta do contexto da pergunta ao BlueJus IA não existe no escritório ou a pessoa não pode vê-la (cadeado,
 * permissão). Os dois casos são o MESMO erro (404), para não confirmar que a pasta existe. A mensagem é fixa: nunca
 * leva id, NUP nem nome.
 */
final class PastaDaIaNaoEncontradaException extends \DomainException
{
    public const MENSAGEM = 'Não encontrei essa pasta ou você não tem acesso.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
