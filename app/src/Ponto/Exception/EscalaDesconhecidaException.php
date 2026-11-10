<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/** A escala pedida na aba Equipe não é uma das 4 do desenho (`CatalogoDeEscalas`). O controller responde 422. */
final class EscalaDesconhecidaException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Escolha uma das escalas da lista.');
    }
}
