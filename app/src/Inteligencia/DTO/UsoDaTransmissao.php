<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O que a transmissão em fluxo ({@see \App\Inteligencia\Service\ProvedorDeLinguagemEmFluxo}) devolve ao terminar: o
 * modelo que respondeu e o consumo REAL de tokens (nulo se o provedor não informou). `cortadaNoLimite` é verdadeiro
 * quando o modelo parou por `max_tokens`: a resposta existe, mas está incompleta.
 */
final readonly class UsoDaTransmissao
{
    public function __construct(
        public string $modelo,
        public ?int $tokensEntrada = null,
        public ?int $tokensSaida = null,
        public ?int $duracaoMs = null,
        public bool $cortadaNoLimite = false,
    ) {
    }
}
