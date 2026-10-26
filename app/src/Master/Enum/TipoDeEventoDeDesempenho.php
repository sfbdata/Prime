<?php

declare(strict_types=1);

namespace App\Master\Enum;

/** Tipos de evento do monitor de saúde (spec docs/specs/master-desempenho.md §3). */
enum TipoDeEventoDeDesempenho: string
{
    case Erro = 'erro';
    case Carga = 'carga';
    case Ping = 'ping';
    case Login = 'login';
    case Upload = 'upload';
}
