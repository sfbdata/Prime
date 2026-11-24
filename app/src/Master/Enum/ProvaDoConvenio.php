<?php

declare(strict_types=1);

namespace App\Master\Enum;

/** Como o escritório entra no convênio (spec docs/specs/convenios.md, §3.1 e §5). */
enum ProvaDoConvenio: string
{
    /** Pela inscrição OAB, sem o escritório fazer nada. */
    case Oab = 'oab';
    /** Pelo código de convite digitado por um primário. */
    case Codigo = 'codigo';
}
