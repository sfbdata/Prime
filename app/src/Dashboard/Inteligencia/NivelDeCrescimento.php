<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\UseCase\DefinirMetaDePastasDoMesUseCase;

/**
 * Os quatro níveis do plano "Aumentar o nível" (dc `NIVEIS`, bluejus-intelligence.js L152-157):
 * Manter, +10%, +20% e +35% sobre a meta de pastas que o escritório já tem.
 * O valor do case é o `id` do desenho (0 a 3).
 */
enum NivelDeCrescimento: int
{
    case Manter = 0;
    case Mais10 = 1;
    case Mais20 = 2;
    case Mais35 = 3;

    /** O nível que o desenho deixa escolhido ao abrir (`iaNivel: 1`). */
    public const PADRAO = self::Mais10;

    public function percentual(): int
    {
        return match ($this) {
            self::Manter => 0,
            self::Mais10 => 10,
            self::Mais20 => 20,
            self::Mais35 => 35,
        };
    }

    /** Nova meta: a meta atual mais o percentual, arredondada para cima (dc `Math.ceil`), no teto da meta (9999). */
    public function novaMeta(int $base): int
    {
        $nova = intdiv($base * (100 + $this->percentual()) + 99, 100);

        return min(DefinirMetaDePastasDoMesUseCase::VALOR_MAXIMO, $nova);
    }
}
