<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Uma opção de voto no resultado da pauta (ApuracaoDaPauta). Pesos em string com 6 casas; percentual em string com 2
 * casas, sobre o peso dos votos válidos da pauta (a abstenção fica de fora).
 */
final readonly class ResultadoDaOpcao
{
    public function __construct(
        public int $numero,
        public string $rotulo,
        public int $unidades,
        public string $peso,
        public string $percentual,
    ) {
    }
}
