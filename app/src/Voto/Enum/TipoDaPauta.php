<?php

declare(strict_types=1);

namespace App\Voto\Enum;

enum TipoDaPauta: string
{
    case Deliberacao = 'deliberacao';
    case EleicaoDeCargo = 'eleicao_cargo';

    public function eleitoral(): bool
    {
        return self::EleicaoDeCargo === $this;
    }
}
