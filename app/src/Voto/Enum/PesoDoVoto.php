<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * Quanto pesa o voto de cada unidade na assembleia (spec docs/specs/voto-assembleia-condominio.md, seção 3): a fração
 * ideal da unidade ou 1 (uma unidade, um voto).
 */
enum PesoDoVoto: string
{
    case Fracao = 'fracao';
    case Unidade = 'unidade';
}
