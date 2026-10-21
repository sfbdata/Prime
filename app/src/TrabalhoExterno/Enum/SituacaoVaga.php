<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/** Situação da vaga (`VST` do desenho VS 18.10.26, linha 838): rótulo e cor. */
enum SituacaoVaga: string
{
    case Pendente = 'pendente';
    case Reprovada = 'reprovada';
    case Aberta = 'aberta';
    case Pausada = 'pausada';
    case Ocupada = 'ocupada';
    case Expirada = 'expirada';
    case Encerrada = 'encerrada';

    public function rotulo(): string
    {
        return match ($this) {
            self::Pendente => 'Aguardando aprovação',
            self::Reprovada => 'Não aprovada',
            self::Aberta => 'Aberta',
            self::Pausada => 'Pausada',
            self::Ocupada => 'Preenchida',
            self::Expirada => 'Expirada',
            self::Encerrada => 'Encerrada',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Pendente, self::Pausada => '#9a5b00',
            self::Reprovada, self::Expirada => '#a3232b',
            self::Aberta => '#1e7b45',
            self::Ocupada => '#0b5f86',
            self::Encerrada => '#6b8494',
        };
    }

    /** Não volta a receber candidatos: só sai da lista de quem não é da Central. */
    public function ehFechada(): bool
    {
        return in_array($this, [self::Reprovada, self::Ocupada, self::Expirada, self::Encerrada], true);
    }
}
