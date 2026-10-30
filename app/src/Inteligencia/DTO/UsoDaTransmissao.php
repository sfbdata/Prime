<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O que a transmissão em fluxo ({@see \App\Inteligencia\Service\ProvedorDeLinguagemEmFluxo}) devolve ao terminar: o
 * modelo que respondeu e o consumo REAL de tokens (nulo se o provedor não informou). `cortadaNoLimite` é verdadeiro
 * quando o modelo parou por `max_tokens`: a resposta existe, mas está incompleta. `chamadasDeFerramenta` são as chamadas de
 * ferramenta completas e com JSON válido que o modelo pediu (a incompleta ou torta é descartada na leitura).
 */
final readonly class UsoDaTransmissao
{
    /** @param list<ChamadaDeFerramenta> $chamadasDeFerramenta */
    public function __construct(
        public string $modelo,
        public ?int $tokensEntrada = null,
        public ?int $tokensSaida = null,
        public ?int $duracaoMs = null,
        public bool $cortadaNoLimite = false,
        public array $chamadasDeFerramenta = [],
    ) {
    }
}
