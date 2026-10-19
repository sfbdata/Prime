<?php

declare(strict_types=1);

namespace App\Central\Exception;

/** O pedido de mudar o limite de ausência não vale: quem pede não é primário (403) ou o valor não é da lista (422). */
final class AusenciaRecusada extends \DomainException
{
    public function __construct(string $mensagem, public readonly bool $semPermissao)
    {
        parent::__construct($mensagem);
    }
}
