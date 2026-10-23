<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/** O andamento de uma candidatura (`AST` do desenho 06, l.846). Só a Central com `talentos` muda. */
enum StatusCandidatura: string
{
    case Recebida = 'recebida';
    case Analise = 'analise';
    case Entrevista = 'entrevista';
    case Aprovado = 'aprovado';
    case Contratado = 'contratado';
    case Nao = 'nao';

    public function rotulo(): string
    {
        return match ($this) {
            self::Recebida => 'Recebida',
            self::Analise => 'Em análise',
            self::Entrevista => 'Entrevista',
            self::Aprovado => 'Aprovado',
            self::Contratado => 'Contratado',
            self::Nao => 'Não selecionado',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Recebida => '#0a6fa0',
            self::Analise => '#9a5b00',
            self::Entrevista => '#5b3f9e',
            self::Aprovado, self::Contratado => '#1e7b45',
            self::Nao => '#6b8494',
        };
    }
}
