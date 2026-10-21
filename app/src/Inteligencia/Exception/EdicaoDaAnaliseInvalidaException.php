<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/** A edição do cartão (P4h) passou do limite de tamanho. Nada é gravado; o controller responde 422. */
final class EdicaoDaAnaliseInvalidaException extends \DomainException
{
}
