<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/** O Master pediu para alterar uma questão jurídica que não está no catálogo (404). */
final class QuestaoJuridicaNaoEncontradaException extends \DomainException
{
    public function __construct(public readonly string $chave)
    {
        parent::__construct('Questão jurídica não encontrada.');
    }
}
