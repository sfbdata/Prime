<?php

declare(strict_types=1);

namespace App\Blog\Exception;

/** O artigo pedido não existe (o controller devolve 404). */
final class ArtigoDoBlogNaoEncontrado extends \DomainException
{
    public static function comId(int $id): self
    {
        return new self(sprintf('Artigo %d não encontrado.', $id));
    }
}
