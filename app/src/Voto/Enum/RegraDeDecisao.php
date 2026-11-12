<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * Critério de decisão de uma pauta (desenho 18, "Critério"): quanto a opção mais votada precisa ter da base de cálculo.
 * Só vale para vaga única; com várias vagas o desenho usa o ranking e o quórum.
 */
enum RegraDeDecisao: string
{
    case Mais = 'mais';
    case Absoluta = 'absoluta';
    case DoisTercos = 'dois';
    case Unanimidade = 'unanime';

    public function rotulo(): string
    {
        return match ($this) {
            self::Mais => 'Mais votos',
            self::Absoluta => 'Maioria absoluta',
            self::DoisTercos => 'Dois terços',
            self::Unanimidade => 'Unanimidade',
        };
    }
}
