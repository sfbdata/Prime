<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/**
 * A logo do documento: a da BlueJus (arquivo estático), a de Meu Escritório (se houver) ou uma própria, enviada com o
 * documento ou guardada como padrão do escritório.
 */
enum TipoDeLogo: string
{
    case Bluejus = 'bluejus';
    case Escritorio = 'escritorio';
    case Propria = 'propria';
}
