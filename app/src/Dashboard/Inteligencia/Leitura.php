<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Uma frase do painel BlueJus Intelligence, sempre amarrada aos números que a sustentam.
 *
 * `tipo` identifica a regra que a gerou (constantes no motor); `severidade` é o peso ou
 * o nível que o desenho dá à regra (ações: peso 0..100; riscos: nível 1..3; explicações:
 * 0..3); `numeros` traz os valores reais usados na frase, para a tela (e os testes)
 * conseguirem rastrear cada afirmação até o dado.
 */
final readonly class Leitura
{
    /** @param array<string, int|float|string> $numeros */
    public function __construct(
        public string $tipo,
        public int $severidade,
        public string $texto,
        public array $numeros = [],
        /** Grupo visual da ação no desenho ("Atenção imediata", "Ação recomendada"…). */
        public ?string $grupo = null,
    ) {}
}
