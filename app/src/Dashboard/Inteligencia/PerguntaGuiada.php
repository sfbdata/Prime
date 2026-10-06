<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Pergunta com resposta montada a partir dos achados (perguntas guiadas do Modo avançado,
 * `bluejus-avancado.js` L220-226, e "Visão estratégica da equipe", dc L2688-2697).
 */
final readonly class PerguntaGuiada
{
    public function __construct(
        public string $pergunta,
        public string $resposta,
    ) {}
}
