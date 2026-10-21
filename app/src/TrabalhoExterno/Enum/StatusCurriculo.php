<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/** A situação do currículo no banco de talentos (`CST` do desenho 06, l.845). Só a Central muda. */
enum StatusCurriculo: string
{
    case Novo = 'novo';
    case Analise = 'analise';
    case Entrevista = 'entrevista';
    case Favorito = 'favorito';
    case Arquivado = 'arquivado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Novo => 'Novo',
            self::Analise => 'Em análise',
            self::Entrevista => 'Entrevista',
            self::Favorito => 'Favorito',
            self::Arquivado => 'Arquivado',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Novo => '#0a6fa0',
            self::Analise => '#9a5b00',
            self::Entrevista => '#5b3f9e',
            self::Favorito => '#1e7b45',
            self::Arquivado => '#6b8494',
        };
    }
}
