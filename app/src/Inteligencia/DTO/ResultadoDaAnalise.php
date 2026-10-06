<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O que qualquer fluxo de análise devolve ao handler depois de interpretar a resposta do modelo:
 * o que vai para `resumo`, `pontos`, `quem_age` e (só nos agentes) `texto_da_analise`. Tipos fora
 * da lista já viraram 'info', travessões já saíram.
 */
final readonly class ResultadoDaAnalise
{
    /**
     * @param list<array{tipo: string, texto: string}> $pontos
     */
    public function __construct(
        public string $resumo,
        public array $pontos,
        public ?string $quemAge,
        public ?string $textoDaAnalise = null,
    ) {
    }

    public static function doPush(RespostaDePushInterpretada $resposta): self
    {
        return new self($resposta->resumo, $resposta->pontos, $resposta->quemAge);
    }
}
