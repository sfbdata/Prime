<?php

declare(strict_types=1);

namespace App\Expediente\DTO;

/**
 * Resposta da Busca rápida: até 8 resultados. Quem abre o quê não é decidido aqui: o resultado de pasta
 * oculta (`restrito`) leva à própria pasta, onde a barreira do cadeado mostra a tela "Pasta trancada".
 */
final class BuscaRapidaOutput
{
    /**
     * @param list<ResultadoDaBuscaRapidaOutput> $resultados
     */
    public function __construct(
        public readonly array $resultados,
    ) {
    }
}
