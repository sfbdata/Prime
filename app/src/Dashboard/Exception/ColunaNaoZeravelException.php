<?php

declare(strict_types=1);

namespace App\Dashboard\Exception;

/** A coluna pedida não existe na tabela Desempenho (ColunasZeraveis). Vira 400 no controller. */
final class ColunaNaoZeravelException extends \DomainException
{
}
