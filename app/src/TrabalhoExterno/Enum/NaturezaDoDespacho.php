<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/** A natureza do despacho da Central (desenho 06 de 24/10, `NAT`, l.1391): opcional, um clique desmarca. */
enum NaturezaDoDespacho: string
{
    case Ciencia = 'ciencia';
    case Providencia = 'providencia';
    case Manifestacao = 'manifestacao';
    case Conferencia = 'conferencia';
    case CumpraSe = 'cumpra_se';

    public function rotulo(): string
    {
        return match ($this) {
            self::Ciencia => 'Para ciência',
            self::Providencia => 'Para providência',
            self::Manifestacao => 'Para manifestação',
            self::Conferencia => 'Para conferência',
            self::CumpraSe => 'Cumpra-se',
        };
    }
}
