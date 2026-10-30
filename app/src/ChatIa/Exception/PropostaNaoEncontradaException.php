<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

/** Proposta que não existe, é de outro escritório ou é de outra pessoa: o MESMO 404, sem confirmar que existe. */
final class PropostaNaoEncontradaException extends \DomainException
{
    public const CODIGO = 'proposta_nao_encontrada';
    public const MENSAGEM = 'Proposta não encontrada.';

    public function __construct(?string $mensagem = null)
    {
        parent::__construct($mensagem ?? self::MENSAGEM);
    }

    public function codigo(): string
    {
        return self::CODIGO;
    }
}
