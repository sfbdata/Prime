<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/** A imagem enviada para a foto da pasta não passa na regra da foto do cabeçalho (tipo ou tamanho). 422. */
final class FotoDaPastaInvalidaException extends \DomainException
{
}
