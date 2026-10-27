<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Viabilidade do plano (dc `viab`, bluejus-intelligence.js L165): o esforço é o ritmo exigido dividido
 * pelo teto de capacidade da equipe. `SemBase` é o caso em que o sistema não tem ritmo atual para
 * comparar (nenhum dia útil decorrido ou nenhuma pasta criada): não se inventa um teto.
 */
enum ViabilidadeDoPlano: string
{
    case Viavel           = 'viavel';
    case Desafiador       = 'desafiador';
    case Arriscado        = 'arriscado';
    case ForaDaCapacidade = 'fora';
    case SemBase          = 'sem_base';

    public static function de(float $esforco): self
    {
        return match (true) {
            $esforco <= 0.8  => self::Viavel,
            $esforco <= 1.0  => self::Desafiador,
            $esforco <= 1.25 => self::Arriscado,
            default          => self::ForaDaCapacidade,
        };
    }

    public function chaveDoRotulo(): string
    {
        return 'dashboard.ia.plano.viab.' . $this->value;
    }

    /** Classe de cor reaproveitada dos quatro estados do selo (adequado, atenção, abaixo, crítico). */
    public function estado(): ?EstadoDoRitmo
    {
        return match ($this) {
            self::Viavel           => EstadoDoRitmo::Adequado,
            self::Desafiador       => EstadoDoRitmo::Atencao,
            self::Arriscado        => EstadoDoRitmo::Abaixo,
            self::ForaDaCapacidade => EstadoDoRitmo::Critico,
            self::SemBase          => null,
        };
    }
}
