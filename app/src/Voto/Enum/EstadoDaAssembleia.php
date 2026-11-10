<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * Estado da assembleia de condomínio (spec docs/specs/voto-assembleia-condominio.md, seção 3). A assembleia é o evento:
 * quem abre e encerra é cada pauta (EstadoDaPauta). Publicar trava as pautas.
 */
enum EstadoDaAssembleia: string
{
    case Rascunho = 'rascunho';
    case Publicada = 'publicada';
}
