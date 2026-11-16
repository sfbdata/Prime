<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * Onde está a avaliação de uma ocorrência registrada para pessoa não apta (spec docs/specs/voto-avaliacoes-do-conselho-1611.md,
 * seção 1). Ocorrência sem avaliação (pessoa apta, ou vinda da importação) guarda `null`, nunca um caso daqui.
 */
enum AvaliacaoDaOcorrencia: string
{
    case Pendente = 'pendente';
    case Mantida = 'mantida';
    case TornadaApta = 'tornada_apta';

    /** O rótulo do desenho (H469): "Pendente", "Mantida" e "Tornado apto". */
    public function rotulo(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Mantida => 'Mantida',
            self::TornadaApta => 'Tornado apto',
        };
    }

    /** A cor do chip do desenho (H469). */
    public function cor(): string
    {
        return match ($this) {
            self::Pendente => '#8a6200',
            self::Mantida => '#a3232b',
            self::TornadaApta => '#1e7b45',
        };
    }
}
