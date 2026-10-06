<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

/**
 * O que `MoverItensDaPastaUseCase` moveu de fato: os itens que estavam DENTRO de uma pasta também
 * selecionada não contam — foram junto com ela, não por conta própria.
 */
final readonly class ResultadoMoverItensDaPasta
{
    public function __construct(
        public int $documentos,
        public int $secoes,
        public ?int $destinoId,
    ) {
    }
}
