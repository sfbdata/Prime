<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Exception;

/** O perfil de quem pede não pode executar a ação (o controller responde 403). */
final class AcaoNaoPermitida extends \DomainException
{
}
