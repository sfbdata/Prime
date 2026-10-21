<?php

declare(strict_types=1);

namespace App\Indicacao\Enum;

/**
 * Por onde a indicação saiu (spec `indicar-bluejus.md`, comportamentos 4 a 6). Os três contam nos limites.
 */
enum CanalDeIndicacao: string
{
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case Link = 'link';
}
