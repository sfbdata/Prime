<?php

declare(strict_types=1);

namespace App\Master\Enum;

/** Categorias do incidente (desenho 05 Perfis 1.2 VS 25.10, `dsNovoInc`). Gravadas pelo código; a tela traduz. */
enum CategoriaDeIncidente: string
{
    case Disponibilidade = 'disponibilidade';
    case Login = 'login';
    case Upload = 'upload';
    case Integracao = 'integracao';
    case BancoDeDados = 'banco';
    case Outro = 'outro';
}
