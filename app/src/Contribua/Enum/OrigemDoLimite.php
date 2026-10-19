<?php

declare(strict_types=1);

namespace App\Contribua\Enum;

/** De onde vem o limite que vale para o escritório. */
enum OrigemDoLimite: string
{
    case Plano = 'plano';
    case Master = 'master';
    case SemLimite = 'sem_limite';
}
