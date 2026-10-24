<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Os modelos que o Master pode escolher no painel (spec ia-anthropic-ligar §10, D1-b). O padrão é o Haiku 5.5.
 *
 * Fable e Mythos ficam FORA de propósito: são "Covered Models" da Anthropic e exigem retenção de 30 dias do lado
 * dela, o que contraria a retenção zero (ZDR) pedida para a organização do BlueJus (§10.2).
 */
enum ModeloDeIa: string
{
    case Haiku55 = 'claude-haiku-5-5';
    case Sonnet55 = 'claude-sonnet-5-5';
    case Opus55 = 'claude-opus-5-5';

    public const PADRAO = self::Haiku55;

    public function rotulo(): string
    {
        return match ($this) {
            self::Haiku55 => 'Claude Haiku 5.5 (mais barato)',
            self::Sonnet55 => 'Claude Sonnet 5.5',
            self::Opus55 => 'Claude Opus 5.5 (mais caro)',
        };
    }

    /**
     * Preço de tabela por milhão de tokens, em dólares (sem o acréscimo de 1,1x do `inference_geo` us).
     *
     * @return array{entrada: float, saida: float}
     */
    public function precoPorMilhao(): array
    {
        return match ($this) {
            self::Haiku55 => ['entrada' => 0.10, 'saida' => 0.50],
            self::Sonnet55 => ['entrada' => 2.0, 'saida' => 10.0],
            self::Opus55 => ['entrada' => 4.0, 'saida' => 20.0],
        };
    }
}
