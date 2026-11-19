<?php

declare(strict_types=1);

namespace App\Contribua\Exception;

/** Inscrição ou devolução da campanha que não existe (o controller responde 404). */
final class CampanhaItemNaoEncontrado extends \DomainException
{
}
