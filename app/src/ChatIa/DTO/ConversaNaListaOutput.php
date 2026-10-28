<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

/**
 * Uma linha da barra lateral: `{id, titulo, fixada, atualizadaEm, grupo}`. `grupo` é uma chave estável
 * (`fixadas`, `hoje`, `ultimos_7_dias`, `mais_antigas`); o rótulo da tela é da tela. `titulo` nulo (conversa sem
 * resposta ainda) vira "Nova conversa".
 */
final readonly class ConversaNaListaOutput
{
    public const GRUPO_FIXADAS = 'fixadas';
    public const GRUPO_HOJE = 'hoje';
    public const GRUPO_ULTIMOS_7_DIAS = 'ultimos_7_dias';
    public const GRUPO_MAIS_ANTIGAS = 'mais_antigas';
    public const TITULO_PADRAO = 'Nova conversa';

    public function __construct(
        public int $id,
        public string $titulo,
        public bool $fixada,
        public string $atualizadaEm,
        public string $grupo,
    ) {
    }

    /** @return array{id: int, titulo: string, fixada: bool, atualizadaEm: string, grupo: string} */
    public function paraArray(): array
    {
        return ['id' => $this->id, 'titulo' => $this->titulo, 'fixada' => $this->fixada, 'atualizadaEm' => $this->atualizadaEm, 'grupo' => $this->grupo];
    }
}
