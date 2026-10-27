<?php

declare(strict_types=1);

namespace App\Pagamento\Enum;

enum MetodoDePagamento: string
{
    case Pix = 'pix';
    case Cartao = 'cartao';
    case Boleto = 'boleto';
    case Manual = 'manual';
}
