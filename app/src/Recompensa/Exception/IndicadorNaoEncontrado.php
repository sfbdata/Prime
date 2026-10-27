<?php

declare(strict_types=1);

namespace App\Recompensa\Exception;

/** O id não é de um indicador (usuário sem nenhuma indicação). O controller devolve 404. */
final class IndicadorNaoEncontrado extends \DomainException
{
}
