<?php

declare(strict_types=1);

namespace App\Blog\Exception;

/** A imagem de artigo pedida não existe (o controller devolve 404). */
final class ImagemDoArtigoNaoEncontrada extends \DomainException
{
    public static function comId(int $id): self
    {
        return new self(sprintf('Imagem %d não encontrada.', $id));
    }
}
