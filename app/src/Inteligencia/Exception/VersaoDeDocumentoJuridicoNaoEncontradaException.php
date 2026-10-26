<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/** A versão de documento jurídico pedida não existe (404). */
final class VersaoDeDocumentoJuridicoNaoEncontradaException extends \DomainException
{
    public function __construct(public readonly int $id)
    {
        parent::__construct('Versão do documento não encontrada.');
    }
}
