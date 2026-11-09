<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/** Modelo do escritório que não existe ou é de outro escritório: as duas coisas viram o mesmo 404, sem dizer qual. */
final class ModeloNaoEncontrado extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Modelo não encontrado.');
    }
}
