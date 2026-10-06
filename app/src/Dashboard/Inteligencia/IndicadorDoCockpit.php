<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/** Um dos indicadores do cockpit do Modo avançado (`bluejus-avancado.js` L205-212). */
final readonly class IndicadorDoCockpit
{
    public function __construct(
        public string $rotulo,
        public string $valor,
        public NivelDeAlerta $nivel,
        public ?string $dica = null,
    ) {}
}
