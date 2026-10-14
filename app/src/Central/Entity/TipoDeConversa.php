<?php

declare(strict_types=1);

namespace App\Central\Entity;

/** A1 só tem a individual; `grupo` entra com a A2 (spec `central-comunicacao-proposta.md`). */
enum TipoDeConversa: string
{
    case Individual = 'individual';
}
