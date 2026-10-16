<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

enum SituacaoProposta: string
{
    case Enviada = 'enviada';
    case Aceita = 'aceita';
    case Ajustes = 'ajustes';
    case Recusada = 'recusada';
    case Substituida = 'substituida';
}
