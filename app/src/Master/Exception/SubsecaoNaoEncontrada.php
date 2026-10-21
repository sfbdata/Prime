<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * A subseção pedida não existe ou não é da seccional da URL (spec master-oab-estados-que-faltam, "Remover").
 * O controller devolve 404.
 */
final class SubsecaoNaoEncontrada extends \DomainException
{
}
