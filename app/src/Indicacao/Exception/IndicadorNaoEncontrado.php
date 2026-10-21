<?php

declare(strict_types=1);

namespace App\Indicacao\Exception;

/** O Master pediu para pausar ou despausar um usuário que não existe. */
final class IndicadorNaoEncontrado extends \DomainException
{
}
