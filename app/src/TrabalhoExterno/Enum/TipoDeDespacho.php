<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/** O que é uma linha do DESPACHO (desenho 06 de 24/10, l.1392): o despacho da Central ou a resposta de uma parte. */
enum TipoDeDespacho: string
{
    case Despacho = 'despacho';
    case Resposta = 'resposta';
}
