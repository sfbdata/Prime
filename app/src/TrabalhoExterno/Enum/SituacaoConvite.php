<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

enum SituacaoConvite: string
{
    case Enviado = 'enviado';
    case Aceito = 'aceito';
    case Revogado = 'revogado';

    /** Texto de "PROFISSIONAIS CONVIDADOS" (`c.st` do desenho). */
    public function rotulo(): string
    {
        return match ($this) {
            self::Enviado => 'Convite enviado',
            self::Aceito => 'Convite aceito',
            self::Revogado => 'Acesso revogado',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Enviado => '#7a5200',
            self::Aceito => '#1e7b45',
            self::Revogado => '#a3232b',
        };
    }
}
