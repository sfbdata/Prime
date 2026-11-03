<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/** O que aconteceu num movimento da {@see AreaDeLiberados}. Nenhum caso sobrescreve nem apaga conteúdo. */
enum MovimentoNoDisco: string
{
    case MOVIDO = 'movido';
    /** A origem não estava lá: nada mudou. */
    case ORIGEM_AUSENTE = 'origem_ausente';
    /** O destino já existia: nada mudou (nunca sobrescreve). */
    case DESTINO_EXISTE = 'destino_existe';
}
