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
    /** O conteúdo recebido não tem o SHA-256 ou o tamanho esperados: nada foi publicado. */
    case CONTEUDO_DIVERGENTE = 'conteudo_divergente';
}
