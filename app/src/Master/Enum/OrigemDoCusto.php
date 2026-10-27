<?php

declare(strict_types=1);

namespace App\Master\Enum;

/** De onde veio o valor do custo (desenho: `ORI`): medido, importado do fornecedor ou estimado. */
enum OrigemDoCusto: string
{
    case Medido = 'medido';
    case Importado = 'importado';
    case Estimado = 'estimado';
}
