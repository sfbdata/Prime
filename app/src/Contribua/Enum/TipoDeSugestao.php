<?php

declare(strict_types=1);

namespace App\Contribua\Enum;

/** Os três tipos do modal "Enviar sugestão" do desenho 08. */
enum TipoDeSugestao: string
{
    case Sugestao = 'sug';
    case Problema = 'prob';
    case Falta = 'falta';

    public function rotulo(): string
    {
        return match ($this) {
            self::Sugestao => 'Sugestão',
            self::Problema => 'Algo atrapalha',
            self::Falta => 'O que falta',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Sugestao => 'bi-lightbulb',
            self::Problema => 'bi-exclamation-triangle',
            self::Falta => 'bi-puzzle',
        };
    }

    /** [cor, fundo] do cartão, como no desenho. */
    public function cores(): array
    {
        return match ($this) {
            self::Sugestao => ['#0a6fa0', '#e5f1f7'],
            self::Problema => ['#9a5b00', '#fff4d6'],
            self::Falta => ['#5b3f9e', '#efeaf8'],
        };
    }

    public function dica(): string
    {
        return match ($this) {
            self::Sugestao => 'Descreva a ideia ou a melhoria que ajudaria no dia a dia do escritório.',
            self::Problema => 'Conte o que está atrapalhando, em qual tela e o que você esperava que acontecesse.',
            self::Falta => 'Qual recurso falta para o seu escritório trabalhar só pelo BlueJus?',
        };
    }
}
