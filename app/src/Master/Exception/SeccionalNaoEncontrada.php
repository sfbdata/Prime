<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * A UF é uma das 27, mas a linha da seccional não existe (banco sem a carga da migration
 * Version20261020148400). O controller devolve 404.
 */
final class SeccionalNaoEncontrada extends \DomainException
{
}
