<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/** Sobre o que o critério é calculado (desenho 18, "Calculado sobre"). */
enum BaseDaDecisao: string
{
    case Validos = 'validos';
    case Presentes = 'presentes';
    case Aptos = 'aptos';

    public function rotulo(): string
    {
        return match ($this) {
            self::Validos => 'Votos válidos',
            self::Presentes => 'Presentes',
            self::Aptos => 'Total de aptos',
        };
    }
}
