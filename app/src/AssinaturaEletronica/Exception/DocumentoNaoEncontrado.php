<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/** Documento que não existe ou é de outro escritório: as duas coisas viram o mesmo 404, sem dizer qual. */
final class DocumentoNaoEncontrado extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Documento não encontrado.');
    }
}
