<?php

declare(strict_types=1);

namespace App\Indicacao\Exception;

/** A indicação não saiu; a mensagem é o texto do desenho, mostrado à pessoa como veio. */
final class IndicacaoRecusada extends \DomainException
{
}
