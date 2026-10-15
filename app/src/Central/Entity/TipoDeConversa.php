<?php

declare(strict_types=1);

namespace App\Central\Entity;

/** Individual (A1: um par) ou grupo (B: nome, administrador, participantes que entram e saem). */
enum TipoDeConversa: string
{
    case Individual = 'individual';
    case Grupo = 'grupo';
}
