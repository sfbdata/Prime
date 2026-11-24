<?php

declare(strict_types=1);

namespace App\Master\Enum;

/** Por onde o escritório entrou no convênio (spec docs/specs/convenios.md, §3.2). */
enum OrigemDoVinculo: string
{
    /** Pela OAB, na sincronização diária. */
    case Oab = 'oab';
    /** Pelo código de convite. */
    case Codigo = 'codigo';
    /** O Master vinculou à mão. */
    case Master = 'master';
}
