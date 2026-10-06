<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O que o provedor devolveu: o texto integral do modelo mais os metadados de custo. `texto` é
 * guardado em `texto_bruto` da análise para auditoria/reparse.
 */
final readonly class RespostaDeLinguagem
{
    public function __construct(
        public string $texto,
        public string $modelo,
        public ?int $tokensEntrada = null,
        public ?int $tokensSaida = null,
        public ?int $duracaoMs = null,
    ) {
    }
}
