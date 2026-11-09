<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/** A forma de assinatura escolhida para cada signatário (spec geral, seção 1.3). */
enum NivelDeAssinatura: string
{
    case Simples = 'simples';
    case Reforcada = 'reforcada';
    case Govbr = 'govbr';
    case Icp = 'icp';
}
