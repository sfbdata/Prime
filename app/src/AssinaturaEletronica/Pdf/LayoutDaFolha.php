<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/** O layout guardado no documento (`DocumentoParaAssinar::getLayout()`): página inteira ou duas colunas. */
enum LayoutDaFolha: string
{
    case PaginaInteira = '';
    case DuasColunas = '2col';
}
