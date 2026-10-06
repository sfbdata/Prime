<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O JSON do modelo já validado e limpo pelo {@see \App\Inteligencia\Service\InterpretadorDeRespostaDePush}:
 * tipos fora da lista viraram 'info', travessões saíram, no máximo 5 pontos.
 */
final readonly class RespostaDePushInterpretada
{
    /**
     * @param list<array{tipo: string, texto: string}> $pontos
     */
    public function __construct(
        public string $resumo,
        public array $pontos,
        public ?string $quemAge,
    ) {
    }
}
