<?php

declare(strict_types=1);

namespace App\Blog\Exception;

/**
 * O artigo não passou nas regras do zod para ir ao ar (o controller devolve 422 com os erros por campo, os mesmos do
 * Verificar).
 */
final class ArtigoDoBlogNaoPublicavel extends \DomainException
{
    /** @param array<string, list<string>> $erros campo => mensagens */
    public function __construct(public readonly array $erros)
    {
        parent::__construct('O artigo ainda não pode ir ao ar. Confira os campos marcados.');
    }
}
