<?php

declare(strict_types=1);

namespace App\Dashboard\Exception;

/** O valor da meta de pastas está fora do que o desenho aceita (inteiro de 1 a 9999). Vira 400 no controller. */
final class MetaDePastasInvalidaException extends \DomainException
{
}
