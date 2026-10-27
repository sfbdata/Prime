<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/** Um marco do plano (dc L1310-1317): onde o total de pastas do mês deve estar ao fim de cada semana útil; o último é o "Fim". */
final readonly class MarcoDoPlano
{
    public function __construct(
        public int $semana,
        public bool $fim,
        public int $alvo,
    ) {
    }
}
