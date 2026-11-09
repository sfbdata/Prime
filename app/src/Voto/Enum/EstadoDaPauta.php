<?php

declare(strict_types=1);

namespace App\Voto\Enum;

/**
 * Estado de uma pauta da assembleia (spec docs/specs/voto-assembleia-condominio.md, seção 3). Cada pauta é uma votação
 * com estado próprio: a mesa abre e encerra uma por vez.
 */
enum EstadoDaPauta: string
{
    case Aguardando = 'aguardando';
    case Aberta = 'aberta';
    case Encerrada = 'encerrada';
}
