<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Estado do ritmo da meta (dc `ESTADOS`, bluejus-intelligence.js L7-12): do adequado ao crítico.
 * A posição é a do marcador na régua de quatro faixas do gauge (centro de cada quarto).
 */
enum EstadoDoRitmo: string
{
    case Adequado = 'adequado';
    case Atencao  = 'atencao';
    case Abaixo   = 'abaixo';
    case Critico  = 'critico';

    /** Posição do marcador no gauge, em % da largura (dc L2620). */
    public function posicao(): float
    {
        return match ($this) {
            self::Adequado => 12.5,
            self::Atencao  => 37.5,
            self::Abaixo   => 62.5,
            self::Critico  => 87.5,
        };
    }

    /** Chave da tradução do rótulo do selo. */
    public function chaveDoRotulo(): string
    {
        return 'dashboard.ia.estado.' . $this->value;
    }
}
